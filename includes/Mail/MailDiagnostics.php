<?php
namespace Wpcb\Mail;

use Wpcb\Admin\Settings;
use Wpcb\Reliability\DeliveryRepository;
use Wpcb\Support\Time;

final class MailDiagnostics {
    public const LAST_RESULT_OPTION = 'wpcb_mail_diagnostics_last';

    /** @var callable|null */
    private $sender;

    public function __construct(?callable $sender = null) {
        $this->sender = $sender;
    }

    public function run(string $recipient): array {
        $recipient = sanitize_email($recipient);
        if (!is_email($recipient)) {
            return $this->persist(false, 'invalid_recipient');
        }

        $settings = Settings::get();
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if (!empty($settings['sender_name']) && !empty($settings['sender_email'])) {
            $headers[] = 'From: ' . sanitize_text_field((string)$settings['sender_name'])
                . ' <' . sanitize_email((string)$settings['sender_email']) . '>';
        }

        $subject = sprintf(
            /* translators: %s: site name */
            __('[%s] WordPress Calendar Booking E-Mail-Test', 'wordpress-calendar-booking'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );
        $body = sprintf(
            /* translators: 1: site URL, 2: UTC timestamp */
            __("Dies ist eine neutrale Test-E-Mail von WordPress Calendar Booking.\n\nWebsite: %1\$s\nZeit (UTC): %2\$s\n\nWenn diese Nachricht ankommt, funktioniert der konfigurierte Mail-Transport bis zu diesem Postfach.", 'wordpress-calendar-booking'),
            home_url('/'),
            Time::formatUtc(Time::nowUtc())
        );

        $deliveries = new DeliveryRepository();
        $key = 'mail-diagnostic:' . wp_generate_uuid4();
        $delivery = $deliveries->begin(0, $key, 'email', 'mail_diagnostic', 'admin', 'wp_mail');
        if (empty($delivery['id']) || empty($delivery['should_run'])) {
            return $this->persist(false, 'delivery_ledger');
        }
        if (!$deliveries->markSending((int)$delivery['id'])) {
            return $this->persist(false, 'delivery_ledger');
        }

        $mailError = null;
        $capture = static function ($error) use (&$mailError): void {
            if ($error instanceof \WP_Error) {
                $mailError = $error;
            }
        };
        add_action('wp_mail_failed', $capture);

        try {
            $sender = $this->sender;
            $sent = $sender
                ? (bool)$sender($recipient, $subject, $body, $headers)
                : wp_mail($recipient, $subject, $body, $headers);

            if ($sent) {
                $deliveries->markSent((int)$delivery['id']);
                return $this->persist(true, '');
            }

            $errorCode = $mailError instanceof \WP_Error
                ? sanitize_key((string)$mailError->get_error_code())
                : 'wp_mail_false';
            $errorMessage = $mailError instanceof \WP_Error
                ? (string)$mailError->get_error_message()
                : 'wp_mail returned false before accepting the diagnostic message.';
            $deliveries->markFailed((int)$delivery['id'], $errorMessage, $errorCode ?: 'wp_mail_false');
            return $this->persist(false, $errorCode ?: 'wp_mail_false');
        } catch (\Throwable $error) {
            $deliveries->markFailed((int)$delivery['id'], $error->getMessage(), 'mail_exception');
            return $this->persist(false, 'mail_exception');
        } finally {
            remove_action('wp_mail_failed', $capture);
        }
    }

    public function lastResult(): array {
        return wp_parse_args(
            (array)get_option(self::LAST_RESULT_OPTION, []),
            [
                'status' => 'untested',
                'tested_at' => '',
                'error_code' => '',
            ]
        );
    }

    private function persist(bool $ok, string $errorCode): array {
        $result = [
            'status' => $ok ? 'accepted' : 'failed',
            'tested_at' => Time::formatUtc(Time::nowUtc()),
            'error_code' => sanitize_key($errorCode),
        ];
        update_option(self::LAST_RESULT_OPTION, $result, false);
        return $result + ['ok' => $ok];
    }
}
