<?php
namespace Wpcb\Webhooks;

final class WebhookSigner {
    public static function sign(string $secret, int $timestamp, string $body): string {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function verify(string $secret, int $timestamp, string $body, string $signature): bool {
        if ($secret === '' || $timestamp < 1 || $signature === '') {
            return false;
        }
        return hash_equals(self::sign($secret, $timestamp, $body), $signature);
    }
}
