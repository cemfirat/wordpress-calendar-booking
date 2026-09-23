<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_mail_diag_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$option = Wpcb\Mail\MailDiagnostics::LAST_RESULT_OPTION;
$oldResult = get_option($option, '__wpcb_missing__');

$wpdb->query(
    "DELETE FROM {$wpdb->prefix}wpcb_deliveries
     WHERE effect_type = 'mail_diagnostic'"
);

$capture = [];
$success = new Wpcb\Mail\MailDiagnostics(
    static function ($to, $subject, $body, $headers) use (&$capture): bool {
        $capture = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'headers' => $headers,
        ];
        return true;
    }
);

$result = $success->run('mail-diagnostic@example.com');
wpcb_mail_diag_assert(!empty($result['ok']) && $result['status'] === 'accepted', 'Successful transport is recorded as accepted.');
wpcb_mail_diag_assert($capture['to'] === 'mail-diagnostic@example.com', 'Diagnostic uses the requested recipient.');
wpcb_mail_diag_assert(strpos((string)$capture['subject'], 'WordPress Calendar Booking') !== false, 'Diagnostic subject is neutral and product-specific.');
wpcb_mail_diag_assert(strpos((string)$capture['body'], 'mail-diagnostic@example.com') === false, 'Diagnostic body does not copy the recipient address.');

$sent = $wpdb->get_row(
    "SELECT * FROM {$wpdb->prefix}wpcb_deliveries
     WHERE effect_type = 'mail_diagnostic'
     ORDER BY id DESC LIMIT 1"
);
wpcb_mail_diag_assert($sent && $sent->status === 'sent', 'Successful diagnostic creates a sent delivery-ledger row.');
$sentSerialized = wp_json_encode($sent);
wpcb_mail_diag_assert(strpos($sentSerialized, 'mail-diagnostic@example.com') === false, 'Delivery ledger does not store diagnostic recipient address.');
wpcb_mail_diag_assert(strpos($sentSerialized, (string)$capture['body']) === false, 'Delivery ledger does not store diagnostic message body.');

$last = $success->lastResult();
$lastSerialized = wp_json_encode($last);
wpcb_mail_diag_assert($last['status'] === 'accepted' && $last['tested_at'] !== '', 'Minimal last-test state is persisted.');
wpcb_mail_diag_assert(strpos($lastSerialized, 'mail-diagnostic@example.com') === false, 'Last-test state does not persist recipient address.');

$failure = new Wpcb\Mail\MailDiagnostics(
    static function (): bool {
        return false;
    }
);
$failedResult = $failure->run('mail-failure@example.com');
wpcb_mail_diag_assert(empty($failedResult['ok']) && $failedResult['error_code'] === 'wp_mail_false', 'False transport result is recorded with a safe error code.');

$failed = $wpdb->get_row(
    "SELECT * FROM {$wpdb->prefix}wpcb_deliveries
     WHERE effect_type = 'mail_diagnostic'
     ORDER BY id DESC LIMIT 1"
);
wpcb_mail_diag_assert($failed && $failed->status === 'failed', 'Failed diagnostic creates a failed delivery-ledger row.');
wpcb_mail_diag_assert((string)$failed->last_error_code === 'wp_mail_false', 'Failed diagnostic stores only the expected safe error code.');

$exception = new Wpcb\Mail\MailDiagnostics(
    static function (): bool {
        throw new RuntimeException('Bearer SUPERSECRETTOKEN password=hunter2 secret=private-value');
    }
);
$exceptionResult = $exception->run('mail-exception@example.com');
wpcb_mail_diag_assert(empty($exceptionResult['ok']) && $exceptionResult['error_code'] === 'mail_exception', 'Transport exception is converted to a diagnostic failure.');

$exceptionRow = $wpdb->get_row(
    "SELECT * FROM {$wpdb->prefix}wpcb_deliveries
     WHERE effect_type = 'mail_diagnostic'
     ORDER BY id DESC LIMIT 1"
);
wpcb_mail_diag_assert($exceptionRow && strpos((string)$exceptionRow->last_error, 'SUPERSECRETTOKEN') === false, 'Bearer token is redacted from diagnostic error storage.');
wpcb_mail_diag_assert(strpos((string)$exceptionRow->last_error, 'hunter2') === false, 'Password value is redacted from diagnostic error storage.');
wpcb_mail_diag_assert(strpos((string)$exceptionRow->last_error, 'private-value') === false, 'Secret value is redacted from diagnostic error storage.');

$admin = get_user_by('login', 'admin');
wpcb_mail_diag_assert($admin !== false, 'WordPress admin fixture user exists.');
wp_set_current_user((int)$admin->ID);

ob_start();
(new Wpcb\Admin\Admin())->schedulerHealth();
$html = (string)ob_get_clean();
wpcb_mail_diag_assert(strpos($html, 'E-Mail-Zustellung') !== false, 'Systemstatus renders mail diagnostics.');
wpcb_mail_diag_assert(strpos($html, 'test_mail_delivery') !== false, 'Systemstatus renders the protected test-mail action.');
wpcb_mail_diag_assert(strpos($html, 'Test-E-Mail senden') !== false, 'Systemstatus renders the test-mail button.');
wpcb_mail_diag_assert(strpos($html, 'nicht die tatsächliche Zustellung') !== false || strpos($html, 'Test-E-Mail konnte nicht') !== false, 'Systemstatus communicates diagnostic result without claiming inbox delivery.');

$wpdb->query(
    "DELETE FROM {$wpdb->prefix}wpcb_deliveries
     WHERE effect_type = 'mail_diagnostic'"
);
if ($oldResult === '__wpcb_missing__') {
    delete_option($option);
} else {
    update_option($option, $oldResult, false);
}

WP_CLI::success('Mail diagnostics smoke test passed.');
