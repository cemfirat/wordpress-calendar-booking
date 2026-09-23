<?php
namespace Wpcb\Api;

use Wpcb\Support\Time;

final class IdempotencyRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_api_idempotency';
    }

    public function find(string $scopeKey): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE scope_key = %s LIMIT 1",
            $scopeKey
        ));
        return $row ?: null;
    }

    public function store(string $scopeKey, string $requestHash, int $statusCode, $response): bool {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $inserted = $wpdb->insert($this->table, [
            'scope_key' => $scopeKey,
            'request_hash' => $requestHash,
            'status_code' => max(100, min(599, $statusCode)),
            'response_json' => wp_json_encode($response),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted !== false) {
            return true;
        }
        $existing = $this->find($scopeKey);
        return $existing && hash_equals((string)$existing->request_hash, $requestHash);
    }
}
