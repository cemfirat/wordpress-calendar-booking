<?php
namespace Wpcb\WaitingList;

final class WaitingListPrivacy {
    public function boot(): void {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'erasers']);
    }

    public function exporters(array $exporters): array {
        $exporters['wpcb-waiting-list'] = [
            'exporter_friendly_name' => __('Calendar Booking waiting list', 'wordpress-calendar-booking'),
            'callback' => [$this, 'exporter'],
        ];
        return $exporters;
    }

    public function erasers(array $erasers): array {
        $erasers['wpcb-waiting-list'] = [
            'eraser_friendly_name' => __('Calendar Booking waiting list', 'wordpress-calendar-booking'),
            'callback' => [$this, 'eraser'],
        ];
        return $erasers;
    }

    public function exporter(string $email, int $page = 1): array {
        if ($page > 1 || !is_email($email)) return ['data' => [], 'done' => true];
        $rows = (new WaitingListRepository())->forEmail(sanitize_email($email), 50);
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'group_id' => 'wpcb-waiting-list',
                'group_label' => __('Calendar Booking waiting list', 'wordpress-calendar-booking'),
                'item_id' => 'waiting-list-' . (int)$row->id,
                'data' => [
                    ['name' => __('Status', 'wordpress-calendar-booking'), 'value' => (string)$row->status],
                    ['name' => __('Appointment start (UTC)', 'wordpress-calendar-booking'), 'value' => (string)$row->slot_start],
                    ['name' => __('Appointment end (UTC)', 'wordpress-calendar-booking'), 'value' => (string)$row->slot_end],
                    ['name' => __('Name', 'wordpress-calendar-booking'), 'value' => (string)$row->full_name],
                    ['name' => __('Email', 'wordpress-calendar-booking'), 'value' => (string)$row->email],
                    ['name' => __('Phone', 'wordpress-calendar-booking'), 'value' => (string)$row->phone],
                ],
            ];
        }
        return ['data' => $data, 'done' => true];
    }

    public function eraser(string $email, int $page = 1): array {
        if ($page > 1 || !is_email($email)) return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
        $removed = (new WaitingListRepository())->eraseForEmail(sanitize_email($email)) > 0;
        return ['items_removed'=>$removed,'items_retained'=>false,'messages'=>[],'done'=>true];
    }
}
