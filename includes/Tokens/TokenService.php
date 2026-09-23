<?php
namespace Wpcb\Tokens;

use Wpcb\Support\Time;

final class TokenService {
    private const SELECTOR_BYTES = 16;
    private const VERIFIER_BYTES = 32;
    private const CLEANUP_RETENTION_DAYS = 30;

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_tokens';
    }

    public function create(int $bookingId, string $type, int $ttlMinutes): string {
        if ($bookingId < 1 || $type === '') {
            throw new \InvalidArgumentException('Booking ID and token type are required.');
        }

        global $wpdb;
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $selector = $this->base64UrlEncode(random_bytes(self::SELECTOR_BYTES));
            $verifier = $this->base64UrlEncode(random_bytes(self::VERIFIER_BYTES));
            $inserted = $wpdb->insert(
                $this->table,
                [
                    'booking_id' => $bookingId,
                    'token_type' => $type,
                    'token_selector' => $selector,
                    'token_hash' => $this->verifierHash($selector, $type, $verifier),
                    'expires_at' => Time::formatUtc(
                        Time::nowUtc()->modify('+' . max(1, $ttlMinutes) . ' minutes')
                    ),
                    'created_at' => Time::formatUtc(Time::nowUtc()),
                ]
            );

            if ($inserted === 1) {
                return $selector . '.' . $verifier;
            }
        }

        throw new \RuntimeException('Unable to create a unique one-time token.');
    }

    /**
     * Inspect a token without mutating it. Intended for GET/status screens.
     *
     * @return array{state:string,row:?object}
     */
    public function inspect(string $token, string $type): array {
        $parts = $this->parseToken($token);
        if (!$parts || $type === '') {
            return ['state' => 'invalid', 'row' => null];
        }

        [$selector, $verifier] = $parts;
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE token_selector = %s
                 AND token_type = %s
                 LIMIT 1",
                $selector,
                $type
            )
        );

        if (!$row) {
            return ['state' => 'invalid', 'row' => null];
        }

        $expected = (string)$row->token_hash;
        $actual = $this->verifierHash($selector, $type, $verifier);
        if ($expected === '' || !hash_equals($expected, $actual)) {
            return ['state' => 'invalid', 'row' => null];
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
        $parts = $this->parseToken($token);
        if (!$parts || $type === '') {
            return new \WP_Error('wpcb_token_invalid', 'This action link is invalid, expired or already used.');
        }

        $selector = $parts[0];
        $lockName = 'wpcb_tok_' . substr(hash('sha256', $type . '|' . $selector), 0, 48);
        if (!$this->acquireLock($lockName, 5)) {
            return new \WP_Error('wpcb_token_busy', 'This action is already being processed. Please try again.');
        }

        try {
            $row = $this->validate($token, $type);
            if (!$row) {
                return new \WP_Error('wpcb_token_invalid', 'This action link is invalid, expired or already used.');
            }

            $result = $callback($row);
            if (is_wp_error($result)) {
                return $result;
            }

            if (!$this->markUsed((int)$row->id)) {
                return new \WP_Error('wpcb_token_race', 'This action link was already used.');
            }

            return $result;
        } finally {
            $this->releaseLock($lockName);
        }
    }

    public function markUsed(int $id): bool {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET used_at = %s
                 WHERE id = %d
                 AND used_at IS NULL",
                $now,
                $id
            )
        );
        return $updated === 1;
    }

    public function revokeForBooking(int $bookingId, ?string $type = null): int {
        if ($bookingId < 1) {
            return 0;
        }

        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        if ($type !== null && $type !== '') {
            return max(
                0,
                (int)$wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$this->table}
                         SET used_at = %s
                         WHERE booking_id = %d
                         AND token_type = %s
                         AND used_at IS NULL",
                        $now,
                        $bookingId,
                        $type
                    )
                )
            );
        }

        return max(
            0,
            (int)$wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$this->table}
                     SET used_at = %s
                     WHERE booking_id = %d
                     AND used_at IS NULL",
                    $now,
                    $bookingId
                )
            )
        );
    }

    public function rotate(int $bookingId, string $type, int $ttlMinutes): string {
        $this->revokeForBooking($bookingId, $type);
        return $this->create($bookingId, $type, $ttlMinutes);
    }

    public function cleanup(int $retentionDays = self::CLEANUP_RETENTION_DAYS): int {
        global $wpdb;
        $retentionDays = max(1, $retentionDays);
        $cutoff = Time::formatUtc(Time::nowUtc()->modify('-' . $retentionDays . ' days'));

        return max(
            0,
            (int)$wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$this->table}
                     WHERE expires_at < %s
                     OR (used_at IS NOT NULL AND used_at < %s)",
                    $cutoff,
                    $cutoff
                )
            )
        );
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function parseToken(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$selector, $verifier] = $parts;
        $selectorBytes = $this->base64UrlDecode($selector);
        $verifierBytes = $this->base64UrlDecode($verifier);
        if ($selectorBytes === null || $verifierBytes === null
            || strlen($selectorBytes) !== self::SELECTOR_BYTES
            || strlen($verifierBytes) !== self::VERIFIER_BYTES
        ) {
            return null;
        }

        return [$selector, $verifier];
    }

    private function verifierHash(string $selector, string $type, string $verifier): string {
        return hash_hmac(
            'sha256',
            $selector . '|' . $type . '|' . $verifier,
            wp_salt('auth') . '|wpcb-one-time-token-v2'
        );
    }

    private function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return null;
        }

        $canonical = $value;
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || !hash_equals($canonical, $this->base64UrlEncode($decoded))) {
            return null;
        }

        return $decoded;
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
