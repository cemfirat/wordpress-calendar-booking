<?php
namespace Wpcb\Webhooks;

use Wpcb\Security\SecretBox;
use Wpcb\Support\Time;

final class WebhookEndpointRepository {
    public const EVENTS = [
        'booking.created',
        'booking.confirmed',
        'booking.pending_approval',
        'booking.rejected',
        'booking.cancelled',
        'booking.rescheduled',
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
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id));
        return $row ?: null;
    }

    public function subscribed(string $event): array {
        if (!in_array($event, self::EVENTS, true)) {
            return [];
        }
        return array_values(array_filter($this->all(true), function (object $row) use ($event): bool {
            $events = json_decode((string)$row->events_json, true);
            return is_array($events) && in_array($event, $events, true);
        }));
    }

    /** @return int|\WP_Error */
    public function save(array $data, int $id = 0) {
        global $wpdb;
        $name = sanitize_text_field((string)($data['name'] ?? ''));
        $url = esc_url_raw((string)($data['url'] ?? ''));
        if ($name === '' || !$this->validUrl($url)) {
            return new \WP_Error('wpcb_webhook_endpoint_invalid', 'Webhook name and a safe HTTPS URL are required.');
        }

        $events = array_values(array_intersect(
            self::EVENTS,
            array_values(array_unique(array_map('sanitize_text_field', (array)($data['events'] ?? []))))
        ));
        if (!$events) {
            return new \WP_Error('wpcb_webhook_events_invalid', 'Select at least one webhook event.');
        }

        $existing = $id > 0 ? $this->find($id) : null;
        if ($id > 0 && !$existing) {
            return new \WP_Error('wpcb_webhook_endpoint_missing', 'Webhook endpoint not found.');
        }

        $row = [
            'name' => $name,
            'url' => $url,
            'events_json' => wp_json_encode($events),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ];

        $plainSecret = (string)($data['secret'] ?? '');
        if ($plainSecret !== '') {
            if (strlen($plainSecret) < 24) {
                return new \WP_Error('wpcb_webhook_secret_short', 'Webhook secrets must be at least 24 characters.');
            }
            $encrypted = $this->secrets->encrypt($plainSecret);
            if (is_wp_error($encrypted)) {
                return $encrypted;
            }
            $row['secret_enc'] = $encrypted;
        } elseif (!$existing) {
            return new \WP_Error('wpcb_webhook_secret_required', 'A webhook secret is required.');
        }

        if ($existing) {
            $saved = $wpdb->update($this->table, $row, ['id' => $id]);
            return $saved === false ? new \WP_Error('wpcb_webhook_storage', 'Webhook endpoint could not be saved.') : $id;
        }

        $row['created_at'] = $row['updated_at'];
        $saved = $wpdb->insert($this->table, $row);
        return $saved === false ? new \WP_Error('wpcb_webhook_storage', 'Webhook endpoint could not be saved.') : (int)$wpdb->insert_id;
    }

    public function delete(int $id): bool {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'wpcb_webhook_jobs', ['endpoint_id' => $id]);
        return $wpdb->delete($this->table, ['id' => $id]) !== false;
    }

    public function secret(object $endpoint): ?string {
        $plain = $this->secrets->decrypt((string)($endpoint->secret_enc ?? ''));
        return $plain === null || $plain === '' ? null : $plain;
    }

    private function validUrl(string $url): bool {
        if ($url === '' || !wp_http_validate_url($url)) {
            return false;
        }
        $scheme = strtolower((string)wp_parse_url($url, PHP_URL_SCHEME));
        if ($scheme === 'https') {
            return true;
        }
        return $scheme === 'http' && (bool)apply_filters('wpcb_webhook_allow_insecure_url', false, $url);
    }
}
