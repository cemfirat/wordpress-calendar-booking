<?php
// WordPress I/O/configuration doubles only. Payment and booking classes are real.
namespace Wpcb\Payments {
    final class StripeConfig {
        public function secretKey(): string { return $GLOBALS['contractSecret'] ?? 'synthetic-test-key'; }
        public function webhookSecret(): string { return 'synthetic-webhook-secret'; }
        public function status(): array { return ['ready'=>true, 'enabled'=>true, 'secret_key'=>'stored', 'webhook_secret'=>'stored']; }
    }
}
namespace {
    class WP_Error {
        public function __construct(private string $code, private string $message = '', private $data = null) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
    class WP_REST_Request {
        public function __construct(private string $body, private string $signature) {}
        public function get_body(): string { return $this->body; }
        public function get_header(string $name): string { return $name === 'stripe-signature' ? $this->signature : ''; }
    }
    class WP_REST_Response {
        public function __construct(public array $data, public int $status) {}
    }
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    function sanitize_text_field($value): string { return trim((string)$value); }
    function sanitize_key($value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$value)); }
    function __($text, $domain = '') { return $text; }
    function esc_html($text): string { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
    function esc_html__($text, $domain = ''): string { return esc_html($text); }
    function esc_attr($text): string { return esc_html($text); }
    function esc_url($text): string { return esc_html($text); }
    function current_user_can($cap): bool { return true; }
    function admin_url($path = ''): string { return 'https://example.test/wp-admin/' . $path; }
    function rest_url($path = ''): string { return 'https://example.test/wp-json/' . $path; }
    function wp_nonce_field($action): void { echo '<input name="_wpnonce" value="synthetic">'; }
    function checked($value, $expected, $echo = true): string { return $value === $expected ? 'checked' : ''; }
    function submit_button($text): void { echo '<button>' . esc_html($text) . '</button>'; }
    function wp_json_encode($value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
    function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-' . sprintf('%012d', ++$GLOBALS['uuidCounter']); }
    if (!function_exists('ctype_digit')) {
        function ctype_digit($value): bool { return is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1; }
    }
    function wp_remote_retrieve_response_code($response): int { return (int)$response['response']['code']; }
    function wp_remote_retrieve_body($response): string { return $response['body']; }
    function wp_remote_request(string $url, array $args) {
        $GLOBALS['httpCalls'][] = [$url, $args];
        check(!empty($GLOBALS['wpdb']->held), 'provider I/O must hold the payment refund lock');
        if (!$GLOBALS['httpReplies']) throw new RuntimeException('Unexpected HTTP request: ' . $args['method'] . ' ' . $url);
        $reply = array_shift($GLOBALS['httpReplies']);
        return is_callable($reply) ? $reply($url, $args) : $reply;
    }
    function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
    function errorCode($result, string $code): void {
        check(is_wp_error($result) && $result->get_error_code() === $code, 'expected error ' . $code);
    }
    function httpReply($body, int $code = 200): array {
        return ['response'=>['code'=>$code], 'body'=>is_string($body) ? $body : wp_json_encode($body)];
    }

    final class RefundContractDatabase {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public string $last_error = '';
        public array $held = [];
        public bool $denyLock = false;
        public $beforeQuery = null;
        private $process;
        private array $pipes = [];
        public function __construct() {
            $this->process = proc_open([getenv('WPCB_CONTRACT_PYTHON') ?: 'python3', __DIR__ . '/refund-contract-db.py'],
                [0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>STDERR], $this->pipes);
            check(is_resource($this->process), 'SQL fixture starts');
        }
        public function close(): void {
            fclose($this->pipes[0]); fclose($this->pipes[1]);
            check(proc_close($this->process) === 0, 'SQL fixture exits cleanly');
        }
        public function prepare(string $sql, ...$args): string {
            $index = 0;
            $prepared = preg_replace_callback('/%[ds]/', static function ($match) use ($args, &$index) {
                $arg = $args[$index++];
                return $match[0] === '%d' ? (string)(int)$arg : "'" . str_replace("'", "''", (string)$arg) . "'";
            }, $sql);
            check($index === count($args), 'prepared SQL argument count');
            return $prepared;
        }
        private function run(string $sql, array $args = []) {
            if ($this->beforeQuery && ($this->beforeQuery)($sql, $args) === false) {
                $this->last_error = 'Injected storage failure';
                return false;
            }
            fwrite($this->pipes[0], wp_json_encode(['sql'=>$sql, 'args'=>$args]) . "\n");
            $line = fgets($this->pipes[1]);
            check($line !== false, 'SQL fixture replies');
            $result = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->last_error = $result['error'] ?? '';
            if ($this->last_error !== '') throw new RuntimeException($this->last_error . ' in ' . $sql);
            $this->insert_id = (int)$result['insert_id'];
            return $result;
        }
        public function query(string $sql) { $result = $this->run($sql); return $result === false ? false : $result['affected']; }
        public function get_results(string $sql): array {
            $result = $this->run($sql);
            return array_map(static fn($row)=>(object)$row, $result['rows']);
        }
        public function get_row(string $sql) { return $this->get_results($sql)[0] ?? null; }
        public function get_var(string $sql) {
            if (preg_match("/GET_LOCK\('([^']+)'/", $sql, $match)) {
                if ($this->denyLock) return 0;
                check(!isset($this->held[$match[1]]), 'no nested refund lock');
                $this->held[$match[1]] = true; return 1;
            }
            if (preg_match("/RELEASE_LOCK\('([^']+)'/", $sql, $match)) {
                unset($this->held[$match[1]]); return 1;
            }
            $row = $this->get_row($sql);
            return $row ? array_values((array)$row)[0] : null;
        }
        private function identifiers(array $fields): array {
            foreach ($fields as $field) check(preg_match('/^[a-z_]+$/D', $field) === 1, 'safe fixture identifier');
            return array_map(static fn($field)=>'`' . $field . '`', $fields);
        }
        public function insert(string $table, array $data) {
            $fields = implode(',', $this->identifiers(array_keys($data)));
            $values = implode(',', array_fill(0, count($data), '?'));
            $result = $this->run("INSERT INTO {$table} ({$fields}) VALUES ({$values})", array_values($data));
            return $result === false ? false : $result['affected'];
        }
        public function update(string $table, array $data, array $where) {
            $fields = implode(',', array_map(static fn($field)=>$field . '=?', $this->identifiers(array_keys($data))));
            $conditions = implode(' AND ', array_map(static fn($field)=>$field . '=?', $this->identifiers(array_keys($where))));
            $result = $this->run("UPDATE {$table} SET {$fields} WHERE {$conditions}", [...array_values($data), ...array_values($where)]);
            return $result === false ? false : $result['affected'];
        }
    }
}
