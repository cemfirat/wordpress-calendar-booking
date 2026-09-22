<?php
namespace Cemb\Tokens;

use Cemb\Support\Time;

class TokenService {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cemb_tokens';
    }

    public function create(int $bookingId, string $type, int $ttlMinutes): string {
        global $wpdb;
        $token = wp_generate_password(48, false, false);
        $wpdb->insert($this->table, [
            'booking_id' => $bookingId,
            'token_type' => $type,
            'token_hash' => wp_hash_password($token),
            'expires_at' => Time::formatUtc(Time::nowUtc()->modify('+' . max(1, $ttlMinutes) . ' minutes')),
            'created_at' => Time::formatUtc(Time::nowUtc()),
        ]);
        return $token;
    }

    /**
     * Inspect a token without mutating it. Intended for GET/status screens.
     *
     * @return array{state:string,row:?object}
     */
    public function inspect(string $token, string $type): array {
        if ($token === '' || $type === '') {
            return ['state' => 'invalid', 'row' => null];
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE token_type = %s ORDER BY id DESC",
                $type
            )
        );

        foreach ($rows as $row) {
            if (!wp_check_password($token, (string)$row->token_hash)) {
                continue;
            }

            if (!empty($row->used_at)) {
                return ['state' => 'used', 'row' => $row];
            }

            $expires = Time::parseUtc((string)$row->expires_at);
            if (!$expires || $expires < Time::nowUtc()) {
                return ['state' => 'expired', 'row' => $row];
            }

            return ['state' => 'valid', 'row' => $row];
        }

        return ['state' => 'invalid', 'row' => null];
    }

    public function validate(string $token, string $type): ?object {
        $inspection = $this->inspect($token, $type);
        return $inspection['state'] === 'valid' ? $inspection['row'] : null;
    }

    /**
     * Serialize one-time token use. The token is consumed only after the
     * callback succeeds; failed validation/domain actions leave it reusable.
     *
     * @return mixed|\WP_Error
     */
    public function consume(string $token, string $type, callable $callback) {
        $lockName = 'cemb_tok_' . substr(hash('sha256', $type . '|' . $token), 0, 48);
        if (!$this->acquireLock($lockName, 5)) {
            return new \WP_Error('cemb_token_busy', 'This action is already being processed. Please try again.');
        }

        try {
            $row = $this->validate($token, $type);
            if (!$row) {
                return new \WP_Error('cemb_token_invalid', 'This action link is invalid, expired or already used.');
            }

            $result = $callback($row);
            if (is_wp_error($result)) {
                return $result;
            }

            if (!$this->markUsed((int)$row->id)) {
                return new \WP_Error('cemb_token_race', 'This action link was already used.');
            }

            return $result;
        } finally {
            $this->releaseLock($lockName);
        }
    }

    public function markUsed(int $id): bool {
        global $wpdb;
        $updated = $wpdb->update(
            $this->table,
            ['used_at' => Time::formatUtc(Time::nowUtc())],
            ['id' => $id, 'used_at' => null]
        );
        return $updated === 1;
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
