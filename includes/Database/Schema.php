<?php
namespace Wpcb\Database;

class Schema {
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'wpcb_';

        $sql = [];
        $sql[] = "CREATE TABLE {$prefix}bookings (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            booking_uuid varchar(64) NOT NULL,
            booking_type_id bigint unsigned NOT NULL,
            resource_id bigint unsigned DEFAULT NULL,
            slot_start datetime NOT NULL,
            slot_end datetime NOT NULL,
            status varchar(50) NOT NULL,
            party_size int NOT NULL DEFAULT 1,
            full_name varchar(190) DEFAULT NULL,
            email varchar(190) NOT NULL,
            phone varchar(100) DEFAULT NULL,
            notes longtext DEFAULT NULL,
            admin_notes longtext DEFAULT NULL,
            source varchar(50) DEFAULT 'frontend',
            lang varchar(10) DEFAULT 'de',
            confirmed_at datetime DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            cancelled_at datetime DEFAULT NULL,
            updated_at_user datetime DEFAULT NULL,
            reserved_until datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY slot_start (slot_start),
            KEY slot_end (slot_end),
            KEY booking_type_id (booking_type_id),
            KEY resource_id (resource_id),
            KEY reserved_until (reserved_until)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}booking_meta (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint unsigned NOT NULL,
            meta_key varchar(190) NOT NULL,
            meta_value longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY meta_key (meta_key)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}booking_types (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            description text DEFAULT NULL,
            duration_minutes int NOT NULL,
            buffer_before_minutes int NOT NULL DEFAULT 0,
            buffer_after_minutes int NOT NULL DEFAULT 0,
            capacity int NOT NULL DEFAULT 1,
            show_remaining_capacity tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            is_public tinyint(1) NOT NULL DEFAULT 1,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY slug (slug)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}resources (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            public_label varchar(190) DEFAULT '',
            description text DEFAULT NULL,
            capacity int NOT NULL DEFAULT 1,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            is_public tinyint(1) NOT NULL DEFAULT 0,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY active_sort (is_active, sort_order)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}booking_type_resources (
            booking_type_id bigint unsigned NOT NULL,
            resource_id bigint unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (booking_type_id, resource_id),
            KEY resource_id (resource_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}form_fields (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            field_key varchar(190) NOT NULL,
            label varchar(190) NOT NULL,
            field_type varchar(50) NOT NULL,
            is_required tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            options_json longtext DEFAULT NULL,
            validation_rules_json longtext DEFAULT NULL,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY field_key (field_key)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}availability_rules (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            scope_type varchar(50) NOT NULL DEFAULT 'global',
            scope_id bigint unsigned DEFAULT NULL,
            weekday tinyint NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,
            slot_duration_minutes int NOT NULL DEFAULT 30,
            buffer_before_minutes int NOT NULL DEFAULT 0,
            buffer_after_minutes int NOT NULL DEFAULT 0,
            min_notice_minutes int NOT NULL DEFAULT 0,
            max_days_in_advance int NOT NULL DEFAULT 30,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY scope (scope_type, scope_id),
            KEY weekday (weekday)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}exceptions (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            type varchar(50) NOT NULL,
            title varchar(190) NOT NULL,
            date_start datetime NOT NULL,
            date_end datetime NOT NULL,
            all_day tinyint(1) NOT NULL DEFAULT 0,
            booking_type_id bigint unsigned DEFAULT NULL,
            resource_id bigint unsigned DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY date_range (date_start, date_end),
            KEY booking_type_id (booking_type_id),
            KEY resource_id (resource_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}tokens (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint unsigned NOT NULL,
            token_type varchar(50) NOT NULL,
            token_selector varchar(32) DEFAULT NULL,
            token_hash varchar(255) NOT NULL,
            expires_at datetime NOT NULL,
            used_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY token_type (token_type),
            UNIQUE KEY token_selector (token_selector),
            KEY expires_at (expires_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}booking_status_log (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint unsigned NOT NULL,
            old_status varchar(50) DEFAULT NULL,
            new_status varchar(50) NOT NULL,
            context varchar(100) DEFAULT NULL,
            changed_by varchar(50) DEFAULT NULL,
            note text DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY new_status (new_status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sync_jobs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint unsigned NOT NULL,
            job_type varchar(50) NOT NULL,
            payload_json longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int NOT NULL DEFAULT 0,
            last_error text DEFAULT NULL,
            idempotency_key varchar(190) DEFAULT NULL,
            lease_owner varchar(64) DEFAULT NULL,
            lease_expires_at datetime DEFAULT NULL,
            available_at datetime NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY status_available (status, available_at),
            KEY lease_expires_at (lease_expires_at),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY job_type (job_type)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}deliveries (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint unsigned NOT NULL,
            idempotency_key varchar(190) NOT NULL,
            channel varchar(30) NOT NULL,
            effect_type varchar(80) NOT NULL,
            recipient_class varchar(30) NOT NULL DEFAULT 'customer',
            provider_code varchar(80) NOT NULL DEFAULT 'wp_mail',
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int NOT NULL DEFAULT 0,
            last_error_code varchar(80) DEFAULT NULL,
            last_error text DEFAULT NULL,
            last_attempt_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY booking_id (booking_id),
            KEY channel_status (channel, status),
            KEY effect_type (effect_type),
            KEY updated_at (updated_at)
        ) {$charset};";


        $sql[] = "CREATE TABLE {$prefix}calendar_connections (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(64) NOT NULL,
            name varchar(190) NOT NULL,
            remote_calendar_id varchar(255) DEFAULT NULL,
            credentials_enc longtext DEFAULT NULL,
            config_json longtext DEFAULT NULL,
            blocks_availability tinyint(1) NOT NULL DEFAULT 1,
            receives_bookings tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            health_status varchar(30) NOT NULL DEFAULT 'unknown',
            last_success_at datetime DEFAULT NULL,
            last_read_at datetime DEFAULT NULL,
            last_write_at datetime DEFAULT NULL,
            last_error_at datetime DEFAULT NULL,
            last_error_message text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY provider (provider),
            KEY active_provider (is_active, provider),
            KEY blocking (is_active, blocks_availability),
            KEY writeback (is_active, receives_bookings)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}booking_type_calendar_connections (
            booking_type_id bigint unsigned NOT NULL,
            connection_id bigint unsigned NOT NULL,
            blocks_availability tinyint(1) NOT NULL DEFAULT 1,
            receives_bookings tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (booking_type_id, connection_id),
            KEY connection_id (connection_id),
            KEY booking_type_blocking (booking_type_id, blocks_availability),
            KEY booking_type_writeback (booking_type_id, receives_bookings)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}resource_calendar_connections (
            resource_id bigint unsigned NOT NULL,
            connection_id bigint unsigned NOT NULL,
            blocks_availability tinyint(1) NOT NULL DEFAULT 1,
            receives_bookings tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (resource_id, connection_id),
            KEY connection_id (connection_id),
            KEY resource_blocking (resource_id, blocks_availability),
            KEY resource_writeback (resource_id, receives_bookings)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sync_log (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint unsigned DEFAULT NULL,
            booking_id bigint unsigned DEFAULT NULL,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY level (level),
            KEY created_at (created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}api_idempotency (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            scope_key varchar(64) NOT NULL,
            request_hash varchar(64) NOT NULL,
            status_code int NOT NULL,
            response_json longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY scope_key (scope_key),
            KEY updated_at (updated_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}webhook_endpoints (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            url text NOT NULL,
            events_json longtext NOT NULL,
            secret_enc longtext NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY active (is_active)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}webhook_deliveries (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            delivery_id varchar(64) NOT NULL,
            endpoint_id bigint unsigned NOT NULL,
            booking_id bigint unsigned NOT NULL,
            event_id varchar(64) NOT NULL,
            event_type varchar(80) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int NOT NULL DEFAULT 0,
            response_code int DEFAULT NULL,
            last_error text DEFAULT NULL,
            last_attempt_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY delivery_id (delivery_id),
            KEY endpoint_status (endpoint_id, status),
            KEY booking_id (booking_id),
            KEY event_id (event_id),
            KEY updated_at (updated_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}customer_sessions (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            selector varchar(32) NOT NULL,
            verifier_hash varchar(64) NOT NULL,
            email_hash varchar(64) NOT NULL,
            email_enc longtext NOT NULL,
            expires_at datetime NOT NULL,
            last_seen_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY selector (selector),
            KEY email_hash (email_hash),
            KEY expires_at (expires_at)
        ) {$charset};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }
}
