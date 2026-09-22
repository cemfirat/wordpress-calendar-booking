<?php
namespace Cemb\Tokens;

use Cemb\Support\Time;

/**
 * Issues and verifies short-lived, tamper-evident booking slot tokens.
 *
 * Tokens intentionally contain no personal data. They are reusable during
 * their short lifetime, but every use must still be revalidated against
 * current server-side availability.
 */
class SlotTokenService {
    private const VERSION = 1;
    private const PURPOSE = 'booking_slot';
    private const DEFAULT_TTL_SECONDS = 1800;

    public function issue(int $typeId, string $start, string $end, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string {
        $payload = [
            'v' => self::VERSION,
            'p' => self::PURPOSE,
            't' => $typeId,
            's' => $start,
            'e' => $end,
            'x' => time() + max(60, $ttlSeconds),
        ];
        $encoded = $this->base64UrlEncode(wp_json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, $this->key(), true);
        return $encoded . '.' . $this->base64UrlEncode($signature);
    }

    public function verify(string $token, ?int $now = null): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$encoded, $encodedSignature] = $parts;
        $signature = $this->base64UrlDecode($encodedSignature);
        if ($signature === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $encoded, $this->key(), true);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $json = $this->base64UrlDecode($encoded);
        if ($json === null) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)
            || ($payload['v'] ?? null) !== self::VERSION
            || ($payload['p'] ?? null) !== self::PURPOSE
            || !isset($payload['t'], $payload['s'], $payload['e'], $payload['x'])
        ) {
            return null;
        }

        $typeId = (int)$payload['t'];
        $start = is_string($payload['s']) ? $payload['s'] : '';
        $end = is_string($payload['e']) ? $payload['e'] : '';
        $expires = (int)$payload['x'];

        if ($typeId < 1
            || !$this->validDateTime($start)
            || !$this->validDateTime($end)
            || Time::parseUtc($end)->getTimestamp() <= Time::parseUtc($start)->getTimestamp()
            || $expires < ($now ?? time())
        ) {
            return null;
        }

        return [
            'type_id' => $typeId,
            'start' => $start,
            'end' => $end,
            'expires_at' => $expires,
        ];
    }

    private function validDateTime(string $value): bool {
        return Time::parseUtc($value) !== null;
    }

    private function key(): string {
        return wp_salt('auth') . '|cemb-slot-token-v1';
    }

    private function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return null;
        }
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
