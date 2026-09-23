<?php
namespace Wpcb\Webhooks;

use Wpcb\Security\SecretBox;
use Wpcb\Support\Time;

final class WebhookEndpointRepository {
    public const EVENTS = [
        'booking.created',
        'booking.confirmed',
        'booking.rejected',
        'booking.rescheduled',
        'booking.cancelled',
    ];

    private string $table;
    private SecretBox $secrets;

    public function __construct(?SecretBox $secrets = null) {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_webhook_endpoints';
        $this->secrets = $secrets ?: new SecretBox();
    }

    public function all(bool $activeOnly = false): array {
        global $wpdb;
        $sql = "SELECT id, name, url, events_json, is_active, created_at, updated_at FROM {$this->table}";
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY id ASC';
        return $wpdb->get_results($sql);
    }

    public function find(int $id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
            $id
        ));
        return $row ?: null;
    }

    public function activeForEvent(string $eventType): array {
        if (!in_array($eventType, self::EVENTS, true)) {
            return [];
        }
        $out = [];
        foreach ($this->all(true) as $endpoint) {
            if (in_array($eventType, $this->events($endpoint), true)) {
                $out[] = $endpoint;
            }
        }
        return $out;
    }

    public function events(object $endpoint): array {
        $decoded = json_decode((string)($endpoint->events_json ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_intersect(self::EVENTS, array_map('strval', $decoded)));
    }

    public function save(array $data, int $id = 0): array|\WP_Error {
        global $wpdb;

        $name = sanitize_text_field((string)($data['name'] ?? ''));
        $url = esc_url_raw(trim((string)($data['url'] ?? '')));
        if ($name === '' || $url === '' || !wp_http_validate_url($url)) {
            return new \WP_Error('wpcb_webhook_invalid', 'Webhook name and a valid HTTP(S) URL are required.');
        }

        $events = array_values(array_intersect(
            self::EVENTS,
            array_values(array_unique(array_map('strval', (array)($data['events'] ?? []))))
        ));
        if (!$events) {
            return new \WP_Error('wpcb_webhook_events', 'Select at least one supported webhook event.');
        }

        $existing = $id > 0 ? $this->find($id) : null;
        if ($id > 0 && !$existing) {
            return new \WP_Error('wpcb_webhook_missing', 'Webhook endpoint not found.');
        }

        $plainSecret = '';
        $secretEnc = $existing ? (string)$existing->secret_enc : '';
        if (!$existing || !empty($data['rotate_secret'])) {
            $plainSecret = bin2hex(random_bytes(32));
            $encrypted = $this->secrets->encrypt($plainSecret);
            if (is_wp_error($encrypted)) {
                return $encrypted;
            }
            $secretEnc = (string)$encrypted;
        }

        $now = Time::formatUtc(Time::nowUtc());
        $row = [
            'name' => $name,
            'url' => $url,
            'events_json' => wp_json_encode($events),
            'secret_enc' => $secretEnc,
            'is_active' => array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1,
            'updated_at' => $now,
        ];

        if ($existing) {
            $ok = $wpdb->update($this->table, $row, ['id' => $id]);
            if ($ok === false) {
                return new \WP_Error('wpcb_webhook_storage', 'Webhook endpoint could not be updated.');
            }
        } else {
            $row['created_at'] = $now;
            if ($wpdb->insert($this->table, $row) === false) {
                return new \WP_Error('wpcb_webhook_storage', 'Webhook endpoint could not be created.');
            }
            $id = (int)$wpdb->insert_id;
        }

        return ['id' => $id, 'secret' => $plainSecret];
    }

    public function delete(int $id): bool {
        global $wpdb;
        return $wpdb->delete($this->table, ['id' => $id]) !== false;
    }

    public function secret(object $endpoint): ?string {
        $plain = $this->secrets->decrypt((string)($endpoint->secret_enc ?? ''));
        return $plain === null || $plain === '' ? null : $plain;
    }
}
