<?php
namespace Cemb\Tokens;

use Cemb\Support\Time;

class TokenService {
    private string $table;
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cemb_tokens';
    }
    public function create(int $bookingId, string $type, int $ttlMinutes): string {
        global $wpdb;
        $token = wp_generate_password(48, false, false);
        $wpdb->insert($this->table, [
            'booking_id' => $bookingId,
            'token_type' => $type,
            'token_hash' => wp_hash_password($token),
            'expires_at' => Time::formatUtc(Time::nowUtc()->modify('+' . max(1, $ttlMinutes) . ' minutes')),
            'created_at' => Time::formatUtc(Time::nowUtc()),
        ]);
        return $token;
    }
    public function validate(string $token, string $type): ?object {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE token_type = %s AND used_at IS NULL ORDER BY id DESC", $type));
        foreach ($rows as $row) {
            $expires = Time::parseUtc((string)$row->expires_at);
            if (!$expires || $expires < Time::nowUtc()) {
                continue;
            }
            if (wp_check_password($token, $row->token_hash)) {
                return $row;
            }
        }
        return null;
    }
    public function markUsed(int $id): void {
        global $wpdb;
        $wpdb->update($this->table, ['used_at' => Time::formatUtc(Time::nowUtc())], ['id' => $id]);
    }
}
