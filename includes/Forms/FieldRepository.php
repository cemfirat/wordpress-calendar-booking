<?php
namespace Cemb\Forms;

class FieldRepository {
    private string $table;
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cemb_form_fields';
    }
    public function active(): array {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    }
}
