<?php
namespace Wpcb\Security;

/**
 * Versioned authenticated encryption for provider credentials.
 *
 * Preferred backend: libsodium secretbox.
 * Fallback: AES-256-GCM via OpenSSL.
 */
final class SecretBox {
    private const VERSION = 'v2';
    private const AAD = 'wpcb-provider-secret:v2';

    public function backend(): string {
        $detected = '';
        if ($this->sodiumAvailable()) {
            $detected = 'sodium';
        } elseif ($this->aesGcmAvailable()) {
            $detected = 'aesgcm';
        }

        $requested = (string)apply_filters('wpcb_secret_storage_backend', $detected);
        if ($requested === 'sodium' && $this->sodiumAvailable()) {
            return 'sodium';
        }
        if ($requested === 'aesgcm' && $this->aesGcmAvailable()) {
            return 'aesgcm';
        }
        return '';
    }

    public function available(): bool {
        return $this->backend() !== '';
    }

    /**
     * @return string|\WP_Error
     */
    public function encrypt(string $plain) {
        if ($plain === '') {
            return '';
        }

        $backend = $this->backend();
        if ($backend === 'sodium') {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key());
            return implode(':', [
                self::VERSION,
                'sodium',
                $this->base64UrlEncode($nonce),
                $this->base64UrlEncode($cipher),
            ]);
        }

        if ($backend === 'aesgcm') {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt(
                $plain,
                'aes-256-gcm',
                $this->key(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                self::AAD,
                16
            );
            if ($cipher === false || strlen($tag) !== 16) {
                return new \WP_Error(
                    'wpcb_secret_encrypt_failed',
                    'Calendar credentials could not be encrypted securely and were not saved.'
                );
            }
            return implode(':', [
                self::VERSION,
                'aesgcm',
                $this->base64UrlEncode($iv),
                $this->base64UrlEncode($tag),
                $this->base64UrlEncode($cipher),
            ]);
        }

        return new \WP_Error(
            'wpcb_secret_crypto_unavailable',
            'Secure credential storage requires libsodium or OpenSSL AES-256-GCM. The secret was not saved.'
        );
    }

    /**
     * Return plaintext only after successful authentication.
     * Null means malformed, unsupported or authentication failed.
     */
    public function decrypt(string $encoded): ?string {
        if ($encoded === '') {
            return '';
        }

        $parts = explode(':', $encoded);
        if (($parts[0] ?? '') !== self::VERSION) {
            return null;
        }

        $backend = (string)($parts[1] ?? '');
        if ($backend === 'sodium' && count($parts) === 4 && $this->sodiumAvailable()) {
            $nonce = $this->base64UrlDecode($parts[2]);
            $cipher = $this->base64UrlDecode($parts[3]);
            if ($nonce === null || $cipher === null || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return null;
            }
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
            return $plain === false ? null : $plain;
        }

        if ($backend === 'aesgcm' && count($parts) === 5 && $this->aesGcmAvailable()) {
            $iv = $this->base64UrlDecode($parts[2]);
            $tag = $this->base64UrlDecode($parts[3]);
            $cipher = $this->base64UrlDecode($parts[4]);
            if ($iv === null || $tag === null || $cipher === null || strlen($iv) !== 12 || strlen($tag) !== 16) {
                return null;
            }
            $plain = openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $this->key(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                self::AAD
            );
            return $plain === false ? null : $plain;
        }

        return null;
    }

    public function formatVersion(string $encoded): string {
        $parts = explode(':', $encoded);
        if (count($parts) >= 2 && $parts[0] === self::VERSION && in_array($parts[1], ['sodium', 'aesgcm'], true)) {
            return $parts[0] . ':' . $parts[1];
        }
        return '';
    }

    private function key(): string {
        return hash_hkdf(
            'sha256',
            wp_salt('auth'),
            32,
            'wpcb/provider-secret/' . self::VERSION
        );
    }

    private function sodiumAvailable(): bool {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')
            && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === 32;
    }

    private function aesGcmAvailable(): bool {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt') || !function_exists('openssl_get_cipher_methods')) {
            return false;
        }
        return in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true);
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
