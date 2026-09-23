<?php
namespace Wpcb\WaitingList;

use Wpcb\Reliability\DeliveryRepository;
use Wpcb\Support\Time;

final class WaitingListNotifier {
    public function sendOffer(object $entry, object $booking, string $token): bool {
        $key = 'waitlist:offer:' . (int)$entry->id . ':' . (int)$booking->id;
        $deliveries = new DeliveryRepository();
        $delivery = $deliveries->begin((int)$booking->id, $key, 'email', 'waitlist:offer', 'customer', 'wp_mail');
        if (empty($delivery['should_run'])) {
            return in_array((string)($delivery['status'] ?? ''), ['sending','sent'], true);
        }
        if (!$deliveries->markSending((int)$delivery['id'])) {
            return true;
        }

        $url = add_query_arg([
            'wpcb_waitlist_offer' => '1',
            'wpcb_waitlist_token' => rawurlencode($token),
        ], home_url('/'));
        $subject = 'Termin auf der Warteliste ist verfügbar';
        $body = "Für Ihren gewünschten Termin ist ein Platz frei geworden.\n\n"
            . 'Termin: ' . Time::display((string)$booking->slot_start, 'd.m.Y H:i') . "\n"
            . 'Teilnehmer: ' . max(1, (int)$booking->party_size) . "\n\n"
            . "Bitte bestätigen Sie das Angebot über diesen Link:\n" . $url . "\n\n"
            . 'Das Angebot verfällt automatisch, wenn die Reservierungsfrist endet.';

        $sent = wp_mail((string)$entry->email, $subject, $body);
        if ($sent) {
            $deliveries->markSent((int)$delivery['id']);
            return true;
        }
        $deliveries->markFailed((int)$delivery['id'], 'wp_mail returned false for waiting-list offer.', 'wp_mail_false');
        return false;
    }
}
