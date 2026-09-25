<?php
// Option storage and named locks below are local doubles, not a WordPress database.
define('WPCB_DIR', dirname(__DIR__, 2) . '/');
require WPCB_DIR . 'includes/Core/Autoloader.php';
Wpcb\Core\Autoloader::register();
$options = ['unrelated' => ['customer' => 'must-not-change']];
$failStore = false; $failDelete = false;
function __($s, $domain = '') { return $s; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function add_option($key, $value, $unused = '', $autoload = null) {
    if ($GLOBALS['failStore'] || isset($GLOBALS['options'][$key])) return false;
    if ($autoload !== false) throw new RuntimeException('Demo must not autoload');
    $GLOBALS['options'][$key] = $value; return true;
}
function delete_option($key) { if ($GLOBALS['failDelete']) return false; unset($GLOBALS['options'][$key]); return true; }
function wp_cache_delete($key, $group) {}
function wp_parse_args($a, $b) { return array_merge($b, $a); }
function wp_timezone_string() { return 'UTC'; }
function get_bloginfo($field) { return 'Synthetic demo site'; }
function home_url($path = '') { return 'https://unit.invalid' . $path; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
class WP_Error {
    public function __construct(public string $code = '', public string $message = '') {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
$wpdb = new class {
    public string $prefix = 'wp_'; public bool $available = true; public int $held = 0;
    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($sql) {
        if (str_contains($sql, 'GET_LOCK')) { if (!$this->available) return 0; $this->held++; return 1; }
        if (str_contains($sql, 'RELEASE_LOCK')) { $this->held--; return 1; }
        throw new RuntimeException('Unexpected database query');
    }
};
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_html__($s, $domain = '') { return esc_html($s); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function admin_url($s) { return 'https://unit.invalid/wp-admin/' . $s; }
function wp_nonce_field($action) { echo '<input name="_wpnonce" value="synthetic">'; }
function current_user_can($cap) { return $GLOBALS['canManage'] ?? false; }
function wp_date($format, $time, $tz) { return (new DateTimeImmutable('@' . $time))->setTimezone($tz)->format($format); }
$passed = 0;
$check = function ($ok, $message) use (&$passed) { if (!$ok) throw new RuntimeException($message); $passed++; echo "PASS: $message\n"; };
$service = new Wpcb\Demo\DemoCalendar();
$check($service->read() === [], 'An untouched installation contains no demo entries');
$first = $service->create();
$check(is_array($first) && count($first['entries']) === 10, 'Optional creation produces exactly ten');
$check($first === $service->create(), 'Repeated creation reuses the complete existing batch');
$check($service->remove() === true && $service->read() === [], 'Removal returns to zero');
$check(count($service->create()['entries']) === 10, 'Create after removal returns to ten');
$GLOBALS['canManage'] = true;
ob_start(); (new Wpcb\Admin\DemoCalendarPage())->render(); $preview = ob_get_clean();
$check(substr_count($preview, 'data-wpcb-demo-entry') === 10 && substr_count($preview, 'href="#demo-10"') >= 2, 'Actual preview renders all entries and multiple days');
$GLOBALS['canManage'] = false;
ob_start(); (new Wpcb\Admin\DemoCalendarPage())->render(); $private = ob_get_clean();
$check($private === '', 'Preview is not rendered without administrator rights');
$service->remove(); $failStore = true;
$check(is_wp_error($service->create()) && $service->read() === [], 'Storage failure reports an error and leaves no partial batch');
$failStore = false;
$check(count($service->create()['entries']) === 10, 'A failed creation can be retried');
$failDelete = true;
$check(is_wp_error($service->remove()) && count($service->read()['entries']) === 10, 'Failed removal does not claim success');
$failDelete = false; $service->remove();
$options[Wpcb\Demo\DemoCalendar::OPTION] = ['bad' => 'data'];
$check(is_wp_error($service->read()) && is_wp_error($service->create()), 'Corrupt demo data is not overwritten or presented as complete');
$check($service->remove() === true, 'Explicit removal can clear only the corrupt demo option');
$wpdb->available = false;
$check(is_wp_error($service->create()) && $service->read() === [], 'Unavailable lock prevents writes');
$wpdb->available = true;
foreach (['2026-03-27', '2026-10-23', '2026-12-29', '2028-02-27'] as $day) {
    $now = new DateTimeImmutable($day . ' 23:50:00', new DateTimeZone('Europe/Vienna'));
    $batch = $service->build($now);
    $check(count($batch['entries']) === 10 && count(array_unique(array_column($batch['entries'], 'id'))) === 10, 'Boundary generation has ten unique entries: ' . $day);
    $weekend = false; $multi = false;
    foreach ($batch['entries'] as $entry) {
        $start = Wpcb\Support\Time::parseUtc($entry['start']); $end = Wpcb\Support\Time::parseUtc($entry['end']);
        if (!$entry['is_demo'] || !$start || !$end || $start <= $now || $end <= $start) throw new RuntimeException('Invalid demo interval');
        $local = $start->setTimezone($now->getTimezone());
        $weekend = $weekend || (int)$local->format('N') >= 6;
        $multi = $multi || $local->format('Y-m-d') !== $end->setTimezone($now->getTimezone())->format('Y-m-d');
    }
    $check($weekend && $multi && $batch['timezone'] === 'Europe/Vienna', 'Weekend, multi-day and timezone examples are present: ' . $day);
}
$check($options === ['unrelated' => ['customer' => 'must-not-change']], 'Real unrelated option data is unchanged');
$check($wpdb->held === 0, 'Every named lock was released');
echo "Demo contracts: $passed passed\n";
