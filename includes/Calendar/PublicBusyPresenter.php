<?php
namespace Cemb\Calendar;

/**
 * Converts private calendar/booking intervals into a public busy-only view model.
 *
 * This class intentionally accepts only timing/type identifiers that are needed
 * for rendering. It does not receive customer metadata, event summaries,
 * locations, attendees or descriptions.
 */
class PublicBusyPresenter {
    public function externalEvent(array $event): array {
        return $this->item(
            (string)($event['start'] ?? ''),
            (string)($event['end'] ?? '')
        );
    }

    public function booking(object $booking): array {
        return $this->item(
            (string)($booking->slot_start ?? ''),
            (string)($booking->slot_end ?? '')
        );
    }

    public function busyLabel(): string {
        return (string)apply_filters('cemb_public_busy_label', __('Besetzt', 'cemb'));
    }

    private function item(string $start, string $end): array {
        $label = $this->busyLabel();
        return [
            'start' => $start,
            'end' => $end,
            'title' => $label,
            'label' => ($start !== '' ? wp_date('H:i', strtotime($start)) . ' ' : '') . $label,
            'type_id' => 0,
            'class' => 'cemb-event-busy',
            'source' => 'busy',
        ];
    }
}
