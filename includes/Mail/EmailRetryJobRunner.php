<?php
namespace Wpcb\Mail;

use Wpcb\Admin\Settings;
use Wpcb\Booking\BookingRepository;
use Wpcb\Reliability\DeliveryRepository;
use Wpcb\Sync\JobRepository;
use Wpcb\Tokens\TokenService;

final class EmailRetryJobRunner {
    public const JOB_TYPE = 'email_notification';

    private const CUSTOMER_TEMPLATES = [
        'doi',
        'pending',
        'confirmed',
        'approved',
        'rejected',
        'cancelled',
        'updated',
        'reminder',
    ];

    private const SPECIAL_KINDS = [
        'portal_login',
        'portal_email_change',
        'waiting_list_offer',
        'video_ready',
    ];

    public static function jobKey(string $deliveryKey): string {
        return 'mail:retry:' . substr(hash('sha256', $deliveryKey), 0, 64);
    }

    public function enqueueTemplate(
        int $bookingId,
        string $deliveryKey,
        string $template,
        bool $attachIcs,
        string $expectedStatus
    ): int {
        if ($bookingId < 1 || !$this->validDeliveryKey($deliveryKey) || !in_array($template, self::CUSTOMER_TEMPLATES, true)) {
            return 0;
        }

        $jobs = new JobRepository();
        $jobId = $jobs->enqueue(
            self::JOB_TYPE,
            $bookingId,
            [
                'kind' => 'template',
                'delivery_key' => $deliveryKey,
                'template' => $template,
                'recipient_class' => 'customer',
                'attach_ics' => $attachIcs ? 1 : 0,
                'expected_status' => sanitize_key($expectedStatus),
            ],
            self::jobKey($deliveryKey)
        );
        if ($jobId > 0) {
            $jobs->deferPending($jobId, 120);
        }
        return $jobId;
    }

    public function enqueueInternal(int $bookingId, string $deliveryKey, string $expectedStatus): int {
        if ($bookingId < 1 || !$this->validDeliveryKey($deliveryKey)) {
            return 0;
        }

        $jobs = new JobRepository();
        $jobId = $jobs->enqueue(
            self::JOB_TYPE,
            $bookingId,
            [
                'kind' => 'internal',
                'delivery_key' => $deliveryKey,
                'recipient_class' => 'admin',
                'expected_status' => sanitize_key($expectedStatus),
            ],
            self::jobKey($deliveryKey)
        );
        if ($jobId > 0) {
            $jobs->deferPending($jobId, 120);
        }
        return $jobId;
    }

    public function enqueuePortalLogin(
        int $bookingId,
        string $deliveryKey,
        string $returnPath,
        string $recipientHash
    ): int {
        if ($bookingId < 1 || !$this->validHash($recipientHash)) {
            return 0;
        }
        return $this->enqueueSpecial($bookingId, $deliveryKey, [
            'kind' => 'portal_login',
            'return_path' => $this->sanitizeReturnPath($returnPath),
            'recipient_hash' => strtolower($recipientHash),
        ]);
    }

    public function enqueuePortalEmailChange(
        int $bookingId,
        string $deliveryKey,
        string $returnPath,
        string $recipientHash
    ): int {
        if ($bookingId < 1 || !$this->validHash($recipientHash)) {
            return 0;
        }
        return $this->enqueueSpecial($bookingId, $deliveryKey, [
            'kind' => 'portal_email_change',
            'return_path' => $this->sanitizeReturnPath($returnPath),
            'recipient_hash' => strtolower($recipientHash),
        ]);
    }

    public function enqueueWaitingListOffer(
        int $entryId,
        string $deliveryKey,
        string $offerGeneration
    ): int {
        if ($entryId < 1 || !$this->validHash($offerGeneration)) {
            return 0;
        }
        return $this->enqueueSpecial(0, $deliveryKey, [
            'kind' => 'waiting_list_offer',
            'entry_id' => $entryId,
            'offer_generation' => strtolower($offerGeneration),
        ]);
    }

    public function enqueueVideoReady(
        int $bookingId,
        string $deliveryKey,
        int $connectionId,
        string $recipientClass,
        string $meetingVersion
    ): int {
        $recipientClass = $recipientClass === 'admin' ? 'admin' : 'customer';
        if ($bookingId < 1 || $connectionId < 1 || !$this->validHash($meetingVersion)) {
            return 0;
        }
        return $this->enqueueSpecial($bookingId, $deliveryKey, [
            'kind' => 'video_ready',
            'connection_id' => $connectionId,
            'recipient_class' => $recipientClass,
            'meeting_version' => strtolower($meetingVersion),
        ]);
    }

    public function run(array $payload, int $bookingId): array {
        $deliveryKey = (string)($payload['delivery_key'] ?? '');
        $kind = sanitize_key((string)($payload['kind'] ?? ''));
        $allowedKinds = array_merge(['template', 'internal'], self::SPECIAL_KINDS);
        if (!$this->validDeliveryKey($deliveryKey)
            || !in_array($kind, $allowedKinds, true)
            || ($bookingId < 1 && $kind !== 'waiting_list_offer')
        ) {
            return ['ok' => false, 'message' => 'Invalid email notification job descriptor.'];
        }

        $deliveries = new DeliveryRepository();
        $delivery = $deliveries->findByKey($deliveryKey);
        if (!$delivery) {
            return ['ok' => true, 'message' => 'Email delivery ledger row no longer exists; retry skipped.'];
        }

        $status = (string)$delivery->status;
        if ($status === 'sent') {
            return ['ok' => true, 'message' => 'Logical email notification was already sent.'];
        }
        if ($status === 'uncertain') {
            return ['ok' => true, 'message' => 'Email outcome is uncertain; automatic retry remains suppressed.'];
        }
        if ($status === 'sending') {
            $deliveries->markUncertain(
                (int)$delivery->id,
                'A previous email attempt ended without a final transport result. Automatic retry was suppressed to avoid a duplicate.',
                'mail_in_flight_unknown'
            );
            return ['ok' => true, 'message' => 'Stale in-flight email was marked uncertain; automatic retry suppressed.'];
        }
        if (!in_array($status, ['pending', 'failed'], true)) {
            return ['ok' => true, 'message' => 'Email delivery is no longer retryable.'];
        }

        if (in_array($kind, self::SPECIAL_KINDS, true)) {
            return (new SpecialNotificationMailer())->runRetry($payload, $bookingId);
        }

        $bookings = new BookingRepository();
        $booking = $bookings->find($bookingId);
        if (!$booking) {
            $deliveries->markFailed(
                (int)$delivery->id,
                'Booking no longer exists; email retry cannot be reconstructed.',
                'booking_missing'
            );
            return ['ok' => true, 'message' => 'Email retry stopped because the booking no longer exists.'];
        }

        $expectedStatus = sanitize_key((string)($payload['expected_status'] ?? ''));
        if ($expectedStatus !== '' && (string)$booking->status !== $expectedStatus) {
            $deliveries->markFailed(
                (int)$delivery->id,
                'Booking state changed before retry; stale notification was suppressed.',
                'notification_obsolete'
            );
            return ['ok' => true, 'message' => 'Stale email notification suppressed after booking state change.'];
        }

        $mailer = new Mailer();
        $bookingArray = (array)$booking;
        $meta = $bookings->getMeta($bookingId);

        if ($kind === 'internal') {
            $sent = $mailer->retryInternalOnce($deliveryKey, $bookingArray, $meta);
        } else {
            $template = sanitize_key((string)($payload['template'] ?? ''));
            if (!in_array($template, self::CUSTOMER_TEMPLATES, true)) {
                return ['ok' => false, 'message' => 'Unsupported email template in retry descriptor.'];
            }
            $links = $this->linksForTemplate($template, $bookingId);
            $sent = $mailer->retryTemplateOnce(
                $deliveryKey,
                $template,
                $bookingArray,
                $meta,
                $links,
                !empty($payload['attach_ics'])
            );
        }

        if ($sent) {
            return ['ok' => true, 'message' => 'Email notification accepted by the configured mail transport.'];
        }

        $after = $deliveries->findByKey($deliveryKey);
        $afterStatus = $after ? (string)$after->status : '';
        if (in_array($afterStatus, ['uncertain', 'sending'], true)) {
            if ($afterStatus === 'sending' && $after) {
                $deliveries->markUncertain(
                    (int)$after->id,
                    'Email attempt ended without a final transport result. Automatic retry was suppressed to avoid a duplicate.',
                    'mail_in_flight_unknown'
                );
            }
            return ['ok' => true, 'message' => 'Email transport outcome is uncertain; automatic retry suppressed.'];
        }

        return ['ok' => false, 'message' => 'Email transport rejected the message before accepting it.'];
    }

    private function enqueueSpecial(int $bookingId, string $deliveryKey, array $payload): int {
        if (!$this->validDeliveryKey($deliveryKey)
            || !in_array((string)($payload['kind'] ?? ''), self::SPECIAL_KINDS, true)
        ) {
            return 0;
        }

        $payload['delivery_key'] = $deliveryKey;
        $jobs = new JobRepository();
        $jobId = $jobs->enqueue(
            self::JOB_TYPE,
            $bookingId,
            $payload,
            self::jobKey($deliveryKey)
        );
        if ($jobId > 0) {
            $jobs->deferPending($jobId, 120);
        }
        return $jobId;
    }

    private function sanitizeReturnPath(string $path): string {
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('/[^A-Za-z0-9_\-\.~\/]/', '', $path);
        return mb_substr($path !== '' ? $path : '/', 0, 500);
    }

    private function validHash(string $value): bool {
        return preg_match('/^[a-f0-9]{64}$/i', $value) === 1;
    }

    private function linksForTemplate(string $template, int $bookingId): array {
        $tokens = new TokenService();

        if ($template === 'doi') {
            $settings = Settings::get();
            $token = $tokens->rotate(
                $bookingId,
                'doi',
                max(1, (int)($settings['token_ttl_minutes'] ?? 1440))
            );
            return ['confirm' => $this->linkUrl('confirm', $token)];
        }

        if (in_array($template, ['pending', 'confirmed', 'approved', 'updated'], true)) {
            $cancel = $tokens->rotate($bookingId, 'cancel', 60 * 24 * 30);
            $update = $tokens->rotate($bookingId, 'update', 60 * 24 * 30);
            return [
                'cancel' => $this->linkUrl('cancel', $cancel),
                'update' => $this->linkUrl('update', $update),
            ];
        }

        return [];
    }

    private function linkUrl(string $action, string $token): string {
        return add_query_arg(
            ['wpcb_action' => $action, 'wpcb_token' => rawurlencode($token)],
            home_url('/')
        );
    }

    private function validDeliveryKey(string $key): bool {
        return $key !== ''
            && strlen($key) <= 190
            && preg_match('/^[A-Za-z0-9:_-]+$/', $key) === 1;
    }
}
