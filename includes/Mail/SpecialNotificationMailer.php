<?php
namespace Wpcb\Mail;

use Wpcb\Admin\Settings;
use Wpcb\Booking\BookingRepository;
use Wpcb\Reliability\DeliveryRepository;
use Wpcb\Security\SecretBox;
use Wpcb\Support\Time;
use Wpcb\Tokens\TokenService;
use Wpcb\VideoMeetings\VideoMeetingRepository;
use Wpcb\WaitingList\WaitingListRepository;

final class SpecialNotificationMailer {
    private BookingRepository $bookings;
    private DeliveryRepository $deliveries;
    private TokenService $tokens;
    private WaitingListRepository $waiting;
    private VideoMeetingRepository $meetings;

    public function __construct() {
        $this->bookings = new BookingRepository();
        $this->deliveries = new DeliveryRepository();
        $this->tokens = new TokenService();
        $this->waiting = new WaitingListRepository();
        $this->meetings = new VideoMeetingRepository();
    }

    public function sendPortalLogin(int $bookingId, string $returnUrl): bool {
        $booking = $this->bookings->find($bookingId);
        $email = $booking ? strtolower(sanitize_email((string)$booking->email)) : '';
        if (!$booking || !is_email($email)) {
            return false;
        }

        $returnPath = $this->returnPath($returnUrl);
        $deliveryKey = 'mail:user:' . $bookingId . ':portal-login:' . wp_generate_uuid4();
        $token = $this->tokens->rotate($bookingId, 'portal_login', 30);
        $url = $this->portalUrl('login', $token, $returnPath);
        $state = $this->attempt(
            $bookingId,
            $deliveryKey,
            'portal_login',
            'customer',
            $email,
            __('Kundenportal – Anmeldelink', 'wordpress-calendar-booking'),
            sprintf(
                __("Öffnen Sie Ihr Kundenportal über diesen Link:\n\n%s\n\nDer Link ist 30 Minuten gültig und kann nur einmal verwendet werden.", 'wordpress-calendar-booking'),
                $url
            )
        );

        if ($state === 'failed') {
            return (new EmailRetryJobRunner())->enqueuePortalLogin(
                $bookingId,
                $deliveryKey,
                $returnPath,
                $this->recipientHash($email)
            ) > 0;
        }
        return $this->acceptedState($state);
    }

    public function sendPortalEmailChange(int $bookingId, string $returnUrl): bool {
        $booking = $this->bookings->find($bookingId);
        if (!$booking) {
            return false;
        }
        $meta = $this->bookings->getMeta($bookingId);
        $email = strtolower(sanitize_email((string)($meta['portal_pending_email'] ?? '')));
        if (!is_email($email)) {
            return false;
        }

        $returnPath = $this->returnPath($returnUrl);
        $deliveryKey = 'mail:user:' . $bookingId . ':portal-email-change:' . wp_generate_uuid4();
        $token = $this->tokens->rotate($bookingId, 'portal_email_change', 60);
        $url = $this->portalUrl('email-change', $token, $returnPath);
        $state = $this->attempt(
            $bookingId,
            $deliveryKey,
            'portal_email_change',
            'customer',
            $email,
            __('Neue E-Mail-Adresse bestätigen', 'wordpress-calendar-booking'),
            sprintf(
                __("Bestätigen Sie Ihre neue E-Mail-Adresse über diesen Link:\n\n%s\n\nDer Link ist 60 Minuten gültig und kann nur einmal verwendet werden.", 'wordpress-calendar-booking'),
                $url
            )
        );

        if ($state === 'failed') {
            return (new EmailRetryJobRunner())->enqueuePortalEmailChange(
                $bookingId,
                $deliveryKey,
                $returnPath,
                $this->recipientHash($email)
            ) > 0;
        }
        return $this->acceptedState($state);
    }

    public function sendWaitingListOffer(int $entryId): bool {
        $entry = $this->waiting->find($entryId);
        if (!$this->validWaitingOffer($entry)) {
            return false;
        }
        $email = strtolower(sanitize_email((string)$entry->email));
        if (!is_email($email)) {
            return false;
        }

        $generation = $this->waitingGeneration($entry);
        $deliveryKey = 'mail:waitlist:' . $entryId . ':offer:' . substr($generation, 0, 40);
        $url = $this->waitingUrl($entry);
        if ($url === '') {
            return false;
        }

        $state = $this->attempt(
            0,
            $deliveryKey,
            'waiting_list_offer',
            'customer',
            $email,
            __('A booking slot is available', 'wordpress-calendar-booking'),
            sprintf(
                __("Hello %s,\n\na place became available for your requested appointment. Confirm within 30 minutes:\n%s", 'wordpress-calendar-booking'),
                (string)$entry->full_name,
                $url
            )
        );
        if ($state === 'failed') {
            return (new EmailRetryJobRunner())->enqueueWaitingListOffer(
                $entryId,
                $deliveryKey,
                $generation
            ) > 0;
        }
        return $this->acceptedState($state);
    }

    public function sendVideoReady(int $bookingId, int $connectionId, string $recipientClass): bool {
        $recipientClass = $recipientClass === 'admin' ? 'admin' : 'customer';
        $message = $this->videoMessage($bookingId, $connectionId, $recipientClass);
        if (!$message) {
            return $recipientClass === 'admin';
        }

        $deliveryKey = ($recipientClass === 'admin' ? 'mail:internal:' : 'mail:user:')
            . $bookingId . ':video-ready:' . $connectionId . ':' . substr($message['version'], 0, 40);
        $state = $this->attempt(
            $bookingId,
            $deliveryKey,
            'video_meeting_ready',
            $recipientClass,
            $message['to'],
            $message['subject'],
            $message['body']
        );
        if ($state === 'failed') {
            return (new EmailRetryJobRunner())->enqueueVideoReady(
                $bookingId,
                $deliveryKey,
                $connectionId,
                $recipientClass,
                $message['version']
            ) > 0;
        }
        return $this->acceptedState($state);
    }

    public function runRetry(array $payload, int $bookingId): array {
        $kind = sanitize_key((string)($payload['kind'] ?? ''));
        $deliveryKey = (string)($payload['delivery_key'] ?? '');

        if ($kind === 'portal_login') {
            return $this->retryPortalLogin($bookingId, $deliveryKey, $payload);
        }
        if ($kind === 'portal_email_change') {
            return $this->retryPortalEmailChange($bookingId, $deliveryKey, $payload);
        }
        if ($kind === 'waiting_list_offer') {
            return $this->retryWaitingListOffer($deliveryKey, $payload);
        }
        if ($kind === 'video_ready') {
            return $this->retryVideoReady($bookingId, $deliveryKey, $payload);
        }

        return ['ok' => false, 'message' => 'Unsupported special email notification descriptor.'];
    }

    private function retryPortalLogin(int $bookingId, string $deliveryKey, array $payload): array {
        $booking = $this->bookings->find($bookingId);
        $email = $booking ? strtolower(sanitize_email((string)$booking->email)) : '';
        if (!$booking || !is_email($email)
            || !hash_equals((string)($payload['recipient_hash'] ?? ''), $this->recipientHash($email))
        ) {
            return $this->obsolete($deliveryKey, 'Portal login recipient changed before retry.');
        }

        $token = $this->tokens->rotate($bookingId, 'portal_login', 30);
        $url = $this->portalUrl('login', $token, (string)($payload['return_path'] ?? '/'));
        return $this->retryAttempt(
            $bookingId,
            $deliveryKey,
            'portal_login',
            'customer',
            $email,
            __('Kundenportal – Anmeldelink', 'wordpress-calendar-booking'),
            sprintf(
                __("Öffnen Sie Ihr Kundenportal über diesen Link:\n\n%s\n\nDer Link ist 30 Minuten gültig und kann nur einmal verwendet werden.", 'wordpress-calendar-booking'),
                $url
            )
        );
    }

    private function retryPortalEmailChange(int $bookingId, string $deliveryKey, array $payload): array {
        $booking = $this->bookings->find($bookingId);
        if (!$booking) {
            return $this->obsolete($deliveryKey, 'Booking disappeared before portal email-change retry.');
        }

        $meta = $this->bookings->getMeta($bookingId);
        $email = strtolower(sanitize_email((string)($meta['portal_pending_email'] ?? '')));
        if (!is_email($email)
            || !hash_equals((string)($payload['recipient_hash'] ?? ''), $this->recipientHash($email))
        ) {
            return $this->obsolete($deliveryKey, 'Pending portal email address changed before retry.');
        }

        $token = $this->tokens->rotate($bookingId, 'portal_email_change', 60);
        $url = $this->portalUrl('email-change', $token, (string)($payload['return_path'] ?? '/'));
        return $this->retryAttempt(
            $bookingId,
            $deliveryKey,
            'portal_email_change',
            'customer',
            $email,
            __('Neue E-Mail-Adresse bestätigen', 'wordpress-calendar-booking'),
            sprintf(
                __("Bestätigen Sie Ihre neue E-Mail-Adresse über diesen Link:\n\n%s\n\nDer Link ist 60 Minuten gültig und kann nur einmal verwendet werden.", 'wordpress-calendar-booking'),
                $url
            )
        );
    }

    private function retryWaitingListOffer(string $deliveryKey, array $payload): array {
        $entryId = absint($payload['entry_id'] ?? 0);
        $entry = $this->waiting->find($entryId);
        $generation = (string)($payload['offer_generation'] ?? '');
        if (!$this->validWaitingOffer($entry)
            || $generation === ''
            || !hash_equals($generation, $this->waitingGeneration($entry))
        ) {
            return $this->obsolete($deliveryKey, 'Waiting-list offer changed or expired before retry.');
        }

        $selector = bin2hex(random_bytes(8));
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $encrypted = (new SecretBox())->encrypt($verifier);
        if (is_wp_error($encrypted)) {
            return ['ok' => false, 'message' => 'Waiting-list retry token could not be encrypted.'];
        }

        $expires = Time::formatUtc(Time::nowUtc()->modify('+30 minutes'));
        if (!$this->waiting->rotateOfferedToken(
            $entryId,
            (string)$entry->offered_at,
            $selector,
            hash('sha256', $verifier),
            (string)$encrypted,
            $expires
        )) {
            return $this->obsolete($deliveryKey, 'Waiting-list offer changed while retry token was rotating.');
        }

        $entry = $this->waiting->find($entryId);
        $email = $entry ? strtolower(sanitize_email((string)$entry->email)) : '';
        $url = $entry ? $this->waitingUrl($entry) : '';
        if (!$entry || !is_email($email) || $url === '') {
            return $this->obsolete($deliveryKey, 'Waiting-list offer is no longer deliverable.');
        }

        return $this->retryAttempt(
            0,
            $deliveryKey,
            'waiting_list_offer',
            'customer',
            $email,
            __('A booking slot is available', 'wordpress-calendar-booking'),
            sprintf(
                __("Hello %s,\n\na place became available for your requested appointment. Confirm within 30 minutes:\n%s", 'wordpress-calendar-booking'),
                (string)$entry->full_name,
                $url
            )
        );
    }

    private function retryVideoReady(int $bookingId, string $deliveryKey, array $payload): array {
        $connectionId = absint($payload['connection_id'] ?? 0);
        $recipientClass = (string)($payload['recipient_class'] ?? '') === 'admin' ? 'admin' : 'customer';
        $message = $this->videoMessage($bookingId, $connectionId, $recipientClass);
        if (!$message
            || !hash_equals((string)($payload['meeting_version'] ?? ''), $message['version'])
        ) {
            return $this->obsolete($deliveryKey, 'Video meeting changed or disappeared before notification retry.');
        }

        return $this->retryAttempt(
            $bookingId,
            $deliveryKey,
            'video_meeting_ready',
            $recipientClass,
            $message['to'],
            $message['subject'],
            $message['body']
        );
    }

    private function retryAttempt(
        int $bookingId,
        string $deliveryKey,
        string $effectType,
        string $recipientClass,
        $to,
        string $subject,
        string $body
    ): array {
        $state = $this->attempt(
            $bookingId,
            $deliveryKey,
            $effectType,
            $recipientClass,
            $to,
            $subject,
            $body
        );
        if ($state === 'failed' || $state === 'ledger_error') {
            return ['ok' => false, 'message' => 'Email transport rejected the reconstructed notification.'];
        }
        return ['ok' => true, 'message' => $state === 'uncertain'
            ? 'Email outcome is uncertain; automatic retry suppressed.'
            : 'Email notification accepted or already completed.'];
    }

    private function attempt(
        int $bookingId,
        string $deliveryKey,
        string $effectType,
        string $recipientClass,
        $to,
        string $subject,
        string $body
    ): string {
        $delivery = $this->deliveries->begin(
            $bookingId,
            $deliveryKey,
            'email',
            $effectType,
            $recipientClass,
            'wp_mail'
        );
        if (empty($delivery['id'])) {
            return 'ledger_error';
        }
        if (empty($delivery['should_run'])) {
            $status = (string)($delivery['status'] ?? '');
            return in_array($status, ['sent', 'uncertain', 'sending'], true) ? $status : 'ledger_error';
        }
        if (!$this->deliveries->markSending((int)$delivery['id'])) {
            $current = $this->deliveries->findByKey($deliveryKey);
            return $current && in_array((string)$current->status, ['sent', 'uncertain', 'sending'], true)
                ? (string)$current->status
                : 'ledger_error';
        }

        try {
            $sent = wp_mail($to, $subject, nl2br(esc_html($body)), ['Content-Type: text/html; charset=UTF-8']);
            if ($sent) {
                $this->deliveries->markSent((int)$delivery['id']);
                return 'sent';
            }
            $this->deliveries->markFailed(
                (int)$delivery['id'],
                'wp_mail returned false before accepting the message.',
                'wp_mail_false'
            );
            return 'failed';
        } catch (\Throwable $error) {
            $this->deliveries->markUncertain(
                (int)$delivery['id'],
                $error->getMessage(),
                'mail_transport_uncertain'
            );
            return 'uncertain';
        }
    }

    private function videoMessage(int $bookingId, int $connectionId, string $recipientClass): ?array {
        $booking = $this->bookings->find($bookingId);
        $meeting = $this->meetings->find($bookingId, $connectionId);
        if (!$booking || !$meeting || (string)$meeting->status !== 'active' || empty($meeting->join_url)) {
            return null;
        }

        $joinUrl = esc_url_raw((string)$meeting->join_url);
        $version = hash_hmac(
            'sha256',
            $joinUrl,
            wp_salt('auth') . '|wpcb-video-mail'
        );

        if ($recipientClass === 'admin') {
            $settings = Settings::get();
            if (empty($settings['notifications_enabled'])) {
                return null;
            }
            $to = array_values(array_filter(array_map(
                'sanitize_email',
                array_map('trim', explode(',', (string)($settings['notification_emails'] ?? '')))
            )));
            if (!$to) {
                return null;
            }
            return [
                'to' => $to,
                'subject' => __('Video meeting ready', 'wordpress-calendar-booking'),
                'body' => sprintf(
                    __("Video meeting for booking #%d is ready.\n\n%s", 'wordpress-calendar-booking'),
                    $bookingId,
                    $joinUrl
                ),
                'version' => $version,
            ];
        }

        $email = strtolower(sanitize_email((string)$booking->email));
        if (!is_email($email)) {
            return null;
        }
        return [
            'to' => $email,
            'subject' => __('Your video meeting is ready', 'wordpress-calendar-booking'),
            'body' => sprintf(
                __("Your video meeting link is ready:\n\n%s", 'wordpress-calendar-booking'),
                $joinUrl
            ),
            'version' => $version,
        ];
    }

    private function validWaitingOffer(?object $entry): bool {
        if (!$entry
            || (string)$entry->status !== 'offered'
            || empty($entry->offer_selector)
            || empty($entry->offer_secret_enc)
            || empty($entry->offered_at)
            || empty($entry->offer_expires_at)
        ) {
            return false;
        }
        $expires = Time::parseUtc((string)$entry->offer_expires_at);
        return $expires && $expires >= Time::nowUtc();
    }

    private function waitingGeneration(object $entry): string {
        return hash_hmac(
            'sha256',
            (string)$entry->entry_uuid . '|' . (string)$entry->offered_at,
            wp_salt('auth') . '|wpcb-waiting-mail'
        );
    }

    private function waitingUrl(object $entry): string {
        $verifier = (new SecretBox())->decrypt((string)$entry->offer_secret_enc);
        if ($verifier === null) {
            return '';
        }
        $token = (string)$entry->offer_selector . '.' . $verifier;
        return add_query_arg([
            'wpcb_waitlist_action' => 'accept',
            'wpcb_waitlist_id' => (int)$entry->id,
            'wpcb_waitlist_token' => rawurlencode($token),
        ], home_url('/'));
    }

    private function portalUrl(string $action, string $token, string $returnPath): string {
        return add_query_arg([
            'wpcb_portal_action' => $action,
            'wpcb_token' => rawurlencode($token),
            'return' => home_url($this->sanitizeReturnPath($returnPath)),
        ], home_url('/'));
    }

    private function returnPath(string $returnUrl): string {
        $safe = wp_validate_redirect(esc_url_raw($returnUrl), home_url('/'));
        $homeHost = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        $host = strtolower((string)wp_parse_url($safe, PHP_URL_HOST));
        if ($host !== '' && $homeHost !== '' && !hash_equals($homeHost, $host)) {
            return '/';
        }
        return $this->sanitizeReturnPath((string)(wp_parse_url($safe, PHP_URL_PATH) ?: '/'));
    }

    private function sanitizeReturnPath(string $path): string {
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('/[^A-Za-z0-9_\-\.~\/]/', '', $path);
        return mb_substr($path !== '' ? $path : '/', 0, 500);
    }

    private function recipientHash(string $email): string {
        return hash_hmac(
            'sha256',
            strtolower(sanitize_email($email)),
            wp_salt('auth') . '|wpcb-mail-recipient'
        );
    }

    private function obsolete(string $deliveryKey, string $message): array {
        $delivery = $this->deliveries->findByKey($deliveryKey);
        if ($delivery) {
            $this->deliveries->markFailed(
                (int)$delivery->id,
                $message,
                'notification_obsolete'
            );
        }
        return ['ok' => true, 'message' => 'Obsolete email notification suppressed.'];
    }

    private function acceptedState(string $state): bool {
        return in_array($state, ['sent', 'uncertain', 'sending'], true);
    }
}
