<?php
namespace Wpcb\WaitingList;

final class WaitingListNotification {
    public function sendOffer(int $entryId, string $token): array {
        $entry = (new WaitingListRepository())->find($entryId);
        if (!$entry || (string)$entry->status !== 'offered') {
            return ['ok' => true, 'message' => 'Waiting-list offer no longer active.'];
        }

        $url = add_query_arg(
            ['wpcb_waitlist_offer' => rawurlencode($token)],
            (string)($entry->return_url ?: home_url('/'))
        );
        $subject = 'Ein Termin ist verfügbar';
        $body = "Für Ihren gewünschten Termin ist Platz frei geworden.\n\n"
            . "Angebot ansehen: " . $url . "\n\n"
            . "Das Angebot ist zeitlich begrenzt und kann nur einmal angenommen werden.";
        $ok = wp_mail((string)$entry->email, $subject, $body);
        return [
            'ok' => (bool)$ok,
            'message' => $ok ? 'Waiting-list offer sent.' : 'Waiting-list offer email failed.',
        ];
    }
}
