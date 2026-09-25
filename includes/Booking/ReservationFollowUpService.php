<?php
namespace Wpcb\Booking;

use Wpcb\Admin\Settings;
use Wpcb\Mail\EmailRetryJobRunner;
use Wpcb\Mail\Mailer;
use Wpcb\Reliability\DeliveryRepository;
use Wpcb\Sync\JobRepository;
use Wpcb\Tokens\TokenService;

/**
 * Idempotent post-reservation orchestration driven by the durable
 * booking-created outbox effect.
 *
 * The logical DOI/internal deliveries are keyed per booking. A DOI verifier is
 * rotated only while no mail could have been accepted yet (new/pending, or a
 * definite failure without a durable retry job). Once a delivery is sending,
 * sent or uncertain its existing token is preserved.
 */
final class ReservationFollowUpService {
    private BookingRepository $bookings;
    private DeliveryRepository $deliveries;
    private JobRepository $jobs;

    public function __construct(
        ?BookingRepository $bookings = null,
        ?DeliveryRepository $deliveries = null,
        ?JobRepository $jobs = null
    ) {
        $this->bookings = $bookings ?: new BookingRepository();
        $this->deliveries = $deliveries ?: new DeliveryRepository();
        $this->jobs = $jobs ?: new JobRepository();
    }

    public function boot(): void {
        add_action('wpcb_booking_created', [$this, 'onCreated'], 5, 1);
    }

    public function onCreated($booking): void {
        $bookingId = (int)($booking->id ?? 0);
        if ($bookingId < 1
            || (string)($booking->status ?? '') !== BookingStatus::RESERVED_UNCONFIRMED
            || (!empty($booking->series_id) && (int)($booking->series_occurrence ?? 0) > 0)
        ) {
            return;
        }

        $lock = 'wpcb_res_follow_' . $bookingId;
        if (!$this->acquireLock($lock, 5)) {
            throw new \RuntimeException('Reservation follow-up is already being prepared.');
        }

        try {
            $current = $this->bookings->find($bookingId);
            if (!$current || (string)$current->status !== BookingStatus::RESERVED_UNCONFIRMED) {
                return;
            }
            $bookingArray = (array)$current;
            $meta = $this->bookings->getMeta($bookingId);
            $this->ensureDoiDelivery($bookingArray, $meta);
            $this->ensureInternalDelivery($bookingArray, $meta);
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function ensureDoiDelivery(array $booking, array $meta): void {
        $bookingId = (int)($booking['id'] ?? 0);
        $key = 'mail:user:' . $bookingId . ':doi';
        $existing = $this->deliveries->findByKey($key);
        if ($this->deliveryNeedsNoNewAttempt($existing, $key)) {
            return;
        }

        $settings = Settings::get();
        $token = (new TokenService())->rotate(
            $bookingId,
            'doi',
            max(1, (int)($settings['token_ttl_minutes'] ?? 1440))
        );
        $accepted = (new Mailer())->sendTemplateOnce(
            $key,
            'doi',
            $booking,
            $meta,
            ['confirm' => $this->linkUrl('confirm', $token)],
            false
        );
        if ($accepted || $this->deliveryIsDurable($key)) {
            return;
        }

        throw new \RuntimeException('DOI notification could not be stored for retry.');
    }

    private function ensureInternalDelivery(array $booking, array $meta): void {
        $bookingId = (int)($booking['id'] ?? 0);
        $key = 'mail:internal:' . $bookingId . ':reserved';
        $existing = $this->deliveries->findByKey($key);
        if ($this->deliveryNeedsNoNewAttempt($existing, $key)) {
            return;
        }

        $accepted = (new Mailer())->sendInternalOnce($key, $booking, $meta);
        if ($accepted || $this->deliveryIsDurable($key)) {
            return;
        }

        throw new \RuntimeException('Internal reservation notification could not be stored for retry.');
    }

    private function deliveryNeedsNoNewAttempt(?object $delivery, string $key): bool {
        if (!$delivery) {
            return false;
        }
        $status = (string)$delivery->status;
        if (in_array($status, ['sending', 'sent', 'uncertain'], true)) {
            return true;
        }
        return $status === 'failed' && $this->hasRetryJob($key);
    }

    private function deliveryIsDurable(string $key): bool {
        $delivery = $this->deliveries->findByKey($key);
        if (!$delivery) {
            return false;
        }
        $status = (string)$delivery->status;
        return in_array($status, ['sending', 'sent', 'uncertain'], true)
            || ($status === 'failed' && $this->hasRetryJob($key));
    }

    private function hasRetryJob(string $deliveryKey): bool {
        $jobKey = EmailRetryJobRunner::jobKey($deliveryKey);
        return isset($this->jobs->findByIdempotencyKeys([$jobKey])[$jobKey]);
    }

    private function linkUrl(string $action, string $token): string {
        return add_query_arg(
            ['wpcb_action' => $action, 'wpcb_token' => rawurlencode($token)],
            home_url('/')
        );
    }

    private function acquireLock(string $name, int $timeoutSeconds): bool {
        global $wpdb;
        return 1 === (int)$wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, max(0, $timeoutSeconds))
        );
    }

    private function releaseLock(string $name): void {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }
}
