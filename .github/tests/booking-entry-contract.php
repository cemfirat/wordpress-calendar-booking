<?php
// Actual subject classes; option storage and wpdb writes are explicit local doubles.
define('WPCB_DIR', dirname(__DIR__, 2) . '/');
require WPCB_DIR . 'includes/Core/Autoloader.php';
Wpcb\Core\Autoloader::register();
$opts = [];
function __($s, $domain = '') { return $s; }
function get_option($key, $default = false) { return $GLOBALS['opts'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['opts'][$key] = $value; return true; }
function wp_parse_args($a, $b) { return array_merge($b, $a); }
function apply_filters($name, $value) { return $value; }
function do_action(...$args) {}
function wp_salt($name = '') { return 'synthetic-test-salt-not-a-credential'; }
function sanitize_text_field($s) { return trim($s); }
function sanitize_textarea_field($s) { return trim($s); }
function sanitize_title($s) { return strtolower(trim($s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($s)); }
function absint($v) { return abs((int)$v); }
function current_time($kind) { return gmdate('Y-m-d H:i:s'); }
class WP_Error {}
function is_wp_error($v) { return $v instanceof WP_Error; }
$wpdb = new class {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public array $data = [];
    public function insert($table, $data) { $this->data = $data; return ++$this->insert_id; }
};
$passed = 0;
$check = function ($condition, $name) use (&$passed) {
    if (!$condition) throw new RuntimeException($name);
    echo "PASS: $name\n"; $passed++;
};
set_error_handler(static function ($code, $message) { throw new RuntimeException($message); });
(new Wpcb\Admin\ConfigurationService())->saveBookingType(['name' => 'Synthetic']);
$check($wpdb->data['payment_mode'] === 'free', 'Missing mode defaults to free without warnings');
(new Wpcb\Admin\ConfigurationService())->saveBookingType(['payment_mode' => 'required']);
$check($wpdb->data['payment_mode'] === 'required', 'Explicit required mode is never downgraded');
$config = new Wpcb\Payments\StripeConfig();
$check(!$config->ready(), 'No configuration is not ready');
$check($config->save(['enabled' => 1, 'secret_key' => 'sk_test_synthetic']) === true, 'Synthetic key stores with authenticated encryption');
$check(!$config->ready(), 'Missing signing secret prevents readiness');
$config->save(['enabled' => 1, 'webhook_secret' => 'whsec_synthetic']);
$check($config->ready(), 'Both decryptable credentials satisfy local readiness, not connectivity');
$config->save(['enabled' => 0]);
$check(!$config->ready(), 'Disabled payment remains disabled with stored credentials');
$opts['wpcb_stripe_settings']['enabled'] = 1;
$opts['wpcb_stripe_settings']['secret_key_enc'] = 'invalid-ciphertext';
$check(!$config->ready(), 'Invalid ciphertext is not accepted');
$guard = new Wpcb\Frontend\BookingEntryGuard();
$booking = (object)['status' => 'reserved_unconfirmed', 'reserved_until' => '2001-01-01 00:00:00'];
$check($guard->expiredReservation($booking), 'Expired hold has a recovery state');
$booking->reserved_until = gmdate('Y-m-d H:i:s', time() + 3600);
$check(!$guard->expiredReservation($booking), 'Live hold remains confirmable');
$booking->reserved_until = null;
$check(!$guard->expiredReservation($booking), 'Legacy null deadline is preserved');
$booking->status = 'expired';
$check($guard->expiredReservation($booking), 'Expired state has recovery even after deadline cleared');
restore_error_handler();
echo "Contracts: $passed passed\n";
