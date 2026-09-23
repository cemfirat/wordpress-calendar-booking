<?php
namespace Wpcb\WaitingList;

final class WaitingListToken {
    private const SELECTOR_BYTES = 16;
    private const VERIFIER_BYTES = 32;

    public function issue(): array {
        $selector = $this->encode(random_bytes(self::SELECTOR_BYTES));
        $verifier = $this->encode(random_bytes(self::VERIFIER_BYTES));
        return [
            'selector' => $selector,
            'verifier' => $verifier,
            'hash' => $this->hash($selector, $verifier),
            'token' => $selector . '.' . $verifier,
        ];
    }

    public function verify(string $token, object $entry): bool {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') return false;
        if (!hash_equals((string)$entry->offer_selector, $parts[0])) return false;
        $expected = (string)$entry->offer_hash;
        return $expected !== '' && hash_equals($expected, $this->hash($parts[0], $parts[1]));
    }

    public function selector(string $token): string {
        $parts = explode('.', $token);
        return count($parts) === 2 ? $parts[0] : '';
    }

    private function hash(string $selector, string $verifier): string {
        return hash_hmac('sha256', $selector . '|' . $verifier, wp_salt('auth') . '|wpcb-waitlist-offer-v1');
    }

    private function encode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
