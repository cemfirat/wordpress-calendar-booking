<?php
namespace Wpcb\Payments;

use Wpcb\Security\SecretBox;

final class StripeConfig {
    private const OPTION = 'wpcb_stripe_settings';

    public function get(): array {
        return wp_parse_args((array)get_option(self::OPTION, []), [
            'enabled' => 0,
            'secret_key_enc' => '',
            'webhook_secret_enc' => '',
        ]);
    }

    public function save(array $input) {
        $current = $this->get();
        $next = [
            'enabled' => empty($input['enabled']) ? 0 : 1,
            'secret_key_enc' => (string)$current['secret_key_enc'],
            'webhook_secret_enc' => (string)$current['webhook_secret_enc'],
        ];
        $box = new SecretBox();

        foreach (['secret_key' => 'secret_key_enc', 'webhook_secret' => 'webhook_secret_enc'] as $plainKey => $storedKey) {
            $plain = trim((string)($input[$plainKey] ?? ''));
            if ($plain === '') {
                continue;
            }
            $encrypted = $box->encrypt($plain);
            if (is_wp_error($encrypted)) {
                return $encrypted;
            }
            $next[$storedKey] = $encrypted;
        }

        update_option(self::OPTION, $next, false);
        return true;
    }

    public function secretKey(): string {
        return $this->decrypt('secret_key_enc');
    }

    public function webhookSecret(): string {
        return $this->decrypt('webhook_secret_enc');
    }

    public function ready(): bool {
        $settings = $this->get();
        return !empty($settings['enabled'])
            && $this->secretKey() !== ''
            && $this->webhookSecret() !== '';
    }

    public function status(): array {
        $settings = $this->get();
        return [
            'enabled' => !empty($settings['enabled']),
            'secret_key' => $this->credentialState((string)$settings['secret_key_enc']),
            'webhook_secret' => $this->credentialState((string)$settings['webhook_secret_enc']),
            'ready' => $this->ready(),
        ];
    }

    private function decrypt(string $field): string {
        $settings = $this->get();
        $encoded = (string)($settings[$field] ?? '');
        if ($encoded === '') {
            return '';
        }
        $plain = (new SecretBox())->decrypt($encoded);
        return $plain === null ? '' : $plain;
    }

    private function credentialState(string $encoded): string {
        if ($encoded === '') {
            return 'empty';
        }
        return (new SecretBox())->decrypt($encoded) === null ? 'invalid' : 'stored';
    }
}
