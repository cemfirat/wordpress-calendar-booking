<?php
namespace Wpcb\Api;

use Wpcb\Support\Time;

final class IdempotencyRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_api_idempotency';
    }

    public function begin(string $key, string $route): array {
        global $wpdb;
        $key = trim($key);
        $route = sanitize_text_field($route);
        if (strlen($key) < 8 || strlen($key) > 190 || $route === '') {
            return ['ok' => false, 'error' => 'invalid'];
        }

        $hash = hash('sha256', $route . "\n" . $key);
        $existing = $this->find($hash);
        if ($existing) {
            return $this->existingResult($existing);
        }

        $now = Time::formatUtc(Time::nowUtc());
        $inserted = $wpdb->insert($this->table, [
            'idempotency_hash' => $hash,
            'route' => $route,
            'status' => 'processing',
            'expires_at' => Time::formatUtc(Time::nowUtc()->modify('+24 hours')),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === false) {
            $existing = $this->find($hash);
            return $existing ? $this->existingResult($existing) : ['ok' => false, 'error' => 'storage'];
        }

        return ['ok' => true, 'owner' => true, 'id' => (int)$wpdb->insert_id, 'hash' => $hash];
    }

    public function complete(int $id, int $code, array $response): void {
        global $wpdb;
        $wpdb->update($this->table, [
            'status' => 'complete',
            'response_code' => $code,
            'response_json' => wp_json_encode($response),
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ], ['id' => $id, 'status' => 'processing']);
    }

    public function abandon(int $id): void {
        global $wpdb;
        $wpdb->delete($this->table, ['id' => $id, 'status' => 'processing']);
    }

    public function cleanup(): int {
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE expires_at < %s",
            Time::formatUtc(Time::nowUtc())
        ));
    }

    private function find(string $hash): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE idempotency_hash = %s LIMIT 1",
            $hash
        )) ?: null;
    }

    private function existingResult(object $row): array {
        if ((string)$row->status === 'complete') {
            $decoded = json_decode((string)$row->response_json, true);
            return [
                'ok' => true,
                'owner' => false,
                'complete' => true,
                'code' => max(100, (int)$row->response_code),
                'response' => is_array($decoded) ? $decoded : [],
            ];
        }
        return ['ok' => true, 'owner' => false, 'complete' => false];
    }
}
