<?php
namespace Wpcb\Tokens;

use Wpcb\Support\Time;
use Wpcb\Resources\ResourceRepository;

/**
 * Issues and verifies short-lived, tamper-evident booking slot tokens.
 *
 * Tokens intentionally contain no personal data. They are reusable during
 * their short lifetime, but every use must still be revalidated against
 * current server-side availability.
 */
class SlotTokenService {
    private const VERSION = 2;
    private const PURPOSE = 'booking_slot';
    private const DEFAULT_TTL_SECONDS = 1800;

    public function issue(
        int $typeId,
        string $start,
        string $end,
        ?int $resourceId = null,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ): string {
        if ($resourceId === null) {
            $resources = (new ResourceRepository())->forBookingType($typeId, true);
            $resourceId = $resources ? (int)$resources[0]->id : 0;
        }
        if ($typeId < 1 || $resourceId < 1) {
            throw new \InvalidArgumentException('Booking type and resource are required for slot tokens.');
        }

        $payload = [
            'v' => self::VERSION,
            'p' => self::PURPOSE,
            't' => $typeId,
            'r' => $resourceId,
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
            || !isset($payload['t'], $payload['r'], $payload['s'], $payload['e'], $payload['x'])
        ) {
            return null;
        }

        $typeId = (int)$payload['t'];
        $resourceId = (int)$payload['r'];
        $start = is_string($payload['s']) ? $payload['s'] : '';
        $end = is_string($payload['e']) ? $payload['e'] : '';
        $expires = (int)$payload['x'];

        if ($typeId < 1
            || $resourceId < 1
            || !(new ResourceRepository())->isAssignedToBookingType($resourceId, $typeId)
            || !$this->validDateTime($start)
            || !$this->validDateTime($end)
            || Time::parseUtc($end)->getTimestamp() <= Time::parseUtc($start)->getTimestamp()
            || $expires < ($now ?? time())
        ) {
            return null;
        }

        return [
            'type_id' => $typeId,
            'resource_id' => $resourceId,
            'start' => $start,
            'end' => $end,
            'expires_at' => $expires,
        ];
    }

    private function validDateTime(string $value): bool {
        return Time::parseUtc($value) !== null;
    }

    private function key(): string {
        return wp_salt('auth') . '|wpcb-slot-token-v2';
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
}
