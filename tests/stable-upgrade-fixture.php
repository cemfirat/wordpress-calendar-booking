<?php
// Disposable CI only. Synthetic records exercise the real old and new plugin.
if (!defined('ABSPATH') || getenv('GITHUB_ACTIONS') !== 'true'
    || !preg_match('/^wpcb_upgrade_[a-f0-9]{16}$/', DB_NAME)) {
    throw new RuntimeException('Refusing non-isolated upgrade fixture');
}
(static function (): void {
    global $wpdb;
    $assert = static function ($ok, string $message): void {
        if (!$ok) throw new RuntimeException($message);
        WP_CLI::log('PASS: ' . $message);
    };
    $snapshot = static function () use ($wpdb): array {
        $result = [];
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'wpcb_') . '%'));
        if (count($tables) !== 27 || $wpdb->last_error !== '') throw new RuntimeException('Incomplete fixture schema');
        sort($tables);
        foreach ($tables as $table) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) throw new RuntimeException('Invalid test table');
            $rows = $wpdb->get_results('SELECT * FROM `' . $table . '`', ARRAY_A);
            if ($wpdb->last_error !== '') throw new RuntimeException('Failed fixture snapshot');
            // Mapping tables use composite keys, not an id column.
            $encoded = array_map('serialize', $rows);
            sort($encoded, SORT_STRING);
            $result[$table] = hash('sha256', serialize($encoded));
        }
        foreach (['wpcb_settings', 'wpcb_email_templates', 'wpcb_stripe_settings', 'wpcb_default_resource_id'] as $key) {
            $result[$key] = hash('sha256', serialize(get_option($key)));
        }
        return $result;
    };
    $mail = 0; $http = 0;
    add_filter('pre_wp_mail', static function ($return) use (&$mail) { $mail++; return false; });
    add_filter('pre_http_request', static function ($return) use (&$http) { $http++; return new WP_Error('unexpected_upgrade_http'); });
    $repository = new Wpcb\Booking\BookingRepository();
    $tokens = new Wpcb\Tokens\TokenService();
    $phase = getenv('WPCB_UPGRADE_PHASE');
    if ($phase === 'seed') {
        $assert(WPCB_VERSION === '3.19.8', 'Synthetic customer records are created with the actual old release loaded.');
        $settings = (array)get_option('wpcb_settings');
        $settings['timezone'] = 'Europe/Vienna';
        $settings['sender_name'] = 'Upgrade fixture sender';
        $settings['sender_email'] = 'sender@example.invalid';
        $settings['notifications_enabled'] = 0;
        $settings['icloud_sync_enabled'] = 0;
        $encrypted = (new Wpcb\Security\SecretBox())->encrypt('synthetic-upgrade-credential');
        $assert(is_string($encrypted) && $encrypted !== '', 'Old release encrypts the synthetic disabled-provider credential.');
        $settings['icloud_sync_password_enc'] = $encrypted;
        update_option('wpcb_settings', $settings, false);
        $stripe = new Wpcb\Payments\StripeConfig();
        $assert($stripe->save(['enabled' => 0, 'secret_key' => 'synthetic-stripe-key', 'webhook_secret' => 'synthetic-webhook-key']) === true, 'Disabled synthetic payment credentials are stored by the old release.');
        $types = (new Wpcb\Booking\BookingTypeRepository())->all(true);
        $assert(count($types) > 0, 'Old installation has seeded booking configuration.');
        $resource = (int)get_option('wpcb_default_resource_id');
        $now = Wpcb\Support\Time::nowUtc();
        $stamp = Wpcb\Support\Time::formatUtc($now);
        $ids = [];
        foreach (['confirmed', 'reserved_unconfirmed', 'cancelled'] as $i => $status) {
            $start = $now->modify('+' . (7 + $i) . ' days');
            $id = $repository->create([
                'booking_uuid' => wp_generate_uuid4(), 'booking_type_id' => (int)$types[0]->id,
                'resource_id' => $resource, 'slot_start' => Wpcb\Support\Time::formatUtc($start),
                'slot_end' => Wpcb\Support\Time::formatUtc($start->modify('+30 minutes')),
                'status' => $status, 'party_size' => 1, 'full_name' => 'Synthetic Upgrade Customer ' . $i,
                'email' => 'upgrade-' . $i . '@example.invalid', 'notes' => 'Preserve synthetic notes',
                'admin_notes' => 'Preserve synthetic admin notes', 'source' => 'upgrade-fixture',
                'reserved_until' => $status === 'reserved_unconfirmed' ? gmdate('Y-m-d H:i:s', time() - 120) : null,
                'created_at' => $stamp, 'updated_at' => $stamp,
            ], ['first_name' => 'Synthetic', 'custom_upgrade_field' => 'must survive'], false);
            $assert($id > 0, 'Old release stores synthetic booking state ' . $status . '.');
            $ids[] = $id;
        }
        // Payment history is inert: no checkout/refund adapter is invoked.
        $assert($wpdb->insert($wpdb->prefix . 'wpcb_payments', [
            'payment_uuid' => wp_generate_uuid4(), 'booking_id' => $ids[0],
            'provider' => 'synthetic', 'provider_reference' => 'upgrade-fixture-001',
            'amount_minor' => 12345, 'currency' => 'EUR', 'status' => 'paid',
            'created_at' => $stamp, 'updated_at' => $stamp,
        ]) === 1, 'Synthetic historical payment exists before the upgrade.');
        $token = $tokens->create($ids[1], 'doi', 120);
        $assert($tokens->inspect($token, 'doi')['state'] === 'valid', 'Old release creates a valid indexed DOI token.');
        $fixture = ['expired_id' => $ids[1], 'token' => $token, 'snapshot' => $snapshot()];
        $assert(add_option('wpcb_upgrade_test_fixture', $fixture, '', false), 'Isolated acceptance snapshot is persisted.');
    } elseif ($phase === 'assert') {
        $assert(WPCB_VERSION === getenv('WPCB_UPGRADE_VERSION'), 'New PHP process boots the exact candidate version.');
        $assert(Wpcb\Database\SchemaMigration::isReady(), 'Upgraded schema verification is complete.');
        $assert(Wpcb\Database\DefaultSeedMigration::isReady(), 'Upgraded default seed migration is complete.');
        $assert(Wpcb\Database\MigrationReadiness::isReady(), 'Upgraded required migrations are ready.');
        $fixture = get_option('wpcb_upgrade_test_fixture');
        $assert(is_array($fixture) && $fixture['snapshot'] === $snapshot(), 'All 27 plugin tables and saved configuration survive the actual upgrade byte-equivalently.');
        $settings = (array)get_option('wpcb_settings');
        $assert((new Wpcb\Security\SecretBox())->decrypt($settings['icloud_sync_password_enc']) === 'synthetic-upgrade-credential', 'Existing encrypted provider credential remains decryptable.');
        $stripe = new Wpcb\Payments\StripeConfig();
        $assert($stripe->secretKey() === 'synthetic-stripe-key' && $stripe->webhookSecret() === 'synthetic-webhook-key' && !$stripe->ready(), 'Payment credentials and intentionally disabled configuration are preserved.');
        $assert($tokens->inspect($fixture['token'], 'doi')['state'] === 'valid', 'Previously issued DOI token remains cryptographically valid.');
        $booking = $repository->find((int)$fixture['expired_id']);
        $assert((new Wpcb\Frontend\BookingEntryGuard())->expiredReservation($booking), 'Upgraded presentation recognizes the expired hold despite a valid old token.');
        $result = (new Wpcb\Booking\BookingTransitionService())->apply((int)$booking->id, 'email_confirmed_automatic');
        $assert(is_wp_error($result) && $result->get_error_code() === 'wpcb_reservation_expired', 'Upgraded domain cannot resurrect an expired old reservation.');
        $assert($tokens->inspect($fixture['token'], 'doi')['state'] === 'valid' && $fixture['snapshot'] === $snapshot(), 'Rejected confirmation consumes no token and mutates no historical data.');
        $demo = new Wpcb\Demo\DemoCalendar();
        $assert($demo->read() === [], 'Upgrade does not silently generate demo data.');
        $created = $demo->create();
        $assert(is_array($created) && count($created['entries']) === 10, 'New optional demo can be explicitly created after an actual upgrade.');
        $assert($demo->remove() === true && $demo->read() === [] && $fixture['snapshot'] === $snapshot(), 'Demo removal preserves all upgraded real-data fixtures.');
    } else {
        throw new RuntimeException('Unknown isolated upgrade fixture phase');
    }
    $assert($mail === 0 && $http === 0, 'Upgrade acceptance operations sent no mail and invoked no external provider.');
})();
