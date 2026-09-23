<?php
namespace Wpcb\Portal;

use Wpcb\Security\SecretBox;
use Wpcb\Support\Time;

final class CustomerSessionRepository {
    public const COOKIE = 'wpcb_portal_session';
    private const SELECTOR_BYTES = 16;
    private const VERIFIER_BYTES = 32;

    private string $table;
    private SecretBox $secrets;

    public function __construct(?SecretBox $secrets = null) {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_customer_sessions';
        $this->secrets = $secrets ?: new SecretBox();
    }

    public function create(string $email, int $ttlMinutes = 1440): string|\WP_Error {
        global $wpdb;
        $email = strtolower(sanitize_email($email));
        if ($email === '') {
            return new \WP_Error('wpcb_portal_email_invalid', 'A valid email address is required.');
        }

        $encrypted = $this->secrets->encrypt($email);
        if (is_wp_error($encrypted)) {
            return $encrypted;
        }

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $selector = $this->encode(random_bytes(self::SELECTOR_BYTES));
            $verifier = $this->encode(random_bytes(self::VERIFIER_BYTES));
            $now = Time::formatUtc(Time::nowUtc());
            $inserted = $wpdb->insert($this->table, [
                'selector' => $selector,
                'verifier_hash' => $this->verifierHash($selector, $verifier),
                'email_hash' => $this->emailHash($email),
                'email_enc' => (string)$encrypted,
                'expires_at' => Time::formatUtc(Time::nowUtc()->modify('+' . max(5, $ttlMinutes) . ' minutes')),
                'last_seen_at' => $now,
                'created_at' => $now,
            ]);
            if ($inserted === 1) {
                return $selector . '.' . $verifier;
            }
        }

        return new \WP_Error('wpcb_portal_session_storage', 'A secure customer session could not be created.');
    }

    /**
     * @return array{row:object,email:string,csrf:string,token:string}|null
     */
    public function authenticate(string $token): ?array {
        $parts = $this->parse($token);
        if (!$parts) {
            return null;
        }
        [$selector, $verifier] = $parts;

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE selector = %s LIMIT 1",
            $selector
        ));
        if (!$row || !hash_equals((string)$row->verifier_hash, $this->verifierHash($selector, $verifier))) {
            return null;
        }

        $expires = Time::parseUtc((string)$row->expires_at);
        if (!$expires || $expires < Time::nowUtc()) {
            $wpdb->delete($this->table, ['id' => (int)$row->id]);
            return null;
        }

        $email = $this->secrets->decrypt((string)$row->email_enc);
        if ($email === null || sanitize_email($email) === '') {
            return null;
        }

        $wpdb->update(
            $this->table,
            ['last_seen_at' => Time::formatUtc(Time::nowUtc())],
            ['id' => (int)$row->id]
        );

        return [
            'row' => $row,
            'email' => strtolower(sanitize_email($email)),
            'csrf' => hash_hmac('sha256', 'wpcb-portal-csrf|' . $selector, $verifier),
            'token' => $token,
        ];
    }

    public function destroy(string $token): void {
        $parts = $this->parse($token);
        if (!$parts) {
            return;
        }
        global $wpdb;
        $wpdb->delete($this->table, ['selector' => $parts[0]]);
    }

    public function deleteForEmail(string $email): int {
        global $wpdb;
        $email = strtolower(sanitize_email($email));
        if ($email === '') {
            return 0;
        }
        return max(0, (int)$wpdb->delete($this->table, ['email_hash' => $this->emailHash($email)]));
    }

    public function cleanup(): int {
        global $wpdb;
        return max(0, (int)$wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE expires_at < %s",
            Time::formatUtc(Time::nowUtc())
        )));
    }

    private function emailHash(string $email): string {
        return hash_hmac('sha256', strtolower($email), wp_salt('auth') . '|wpcb-portal-email');
    }

    private function verifierHash(string $selector, string $verifier): string {
        return hash_hmac('sha256', $selector . '|' . $verifier, wp_salt('auth') . '|wpcb-portal-session');
    }

    private function parse(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        $selectorBytes = $this->decode($parts[0]);
        $verifierBytes = $this->decode($parts[1]);
        if ($selectorBytes === null || $verifierBytes === null
            || strlen($selectorBytes) !== self::SELECTOR_BYTES
            || strlen($verifierBytes) !== self::VERIFIER_BYTES) {
            return null;
        }
        return [$parts[0], $parts[1]];
    }

    private function encode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): ?string {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return null;
        }
        $canonical = $value;
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || !hash_equals($canonical, $this->encode($decoded))) {
            return null;
        }
        return $decoded;
    }
}
