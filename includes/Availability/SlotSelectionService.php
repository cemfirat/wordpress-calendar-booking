<?php
namespace Wpcb\Availability;

use Wpcb\Tokens\SlotTokenService;

/**
 * Resolves a browser-submitted slot token into a currently bookable slot.
 *
 * A valid signature alone is never sufficient: availability is regenerated
 * and checked again immediately before the caller reserves/updates a booking.
 */
class SlotSelectionService {
    private SlotTokenService $tokens;
    private SlotService $slots;

    public function __construct(?SlotTokenService $tokens = null, ?SlotService $slots = null) {
        $this->tokens = $tokens ?: new SlotTokenService();
        $this->slots = $slots ?: new SlotService();
    }

    public function resolve(string $token, ?int $expectedTypeId = null, ?int $ignoreBookingId = null, int $partySize = 1): ?array {
        $payload = $this->tokens->verify($token);
        if (!$payload) {
            return null;
        }
        if ($expectedTypeId !== null && $payload['type_id'] !== $expectedTypeId) {
            return null;
        }
        if (!$this->slots->isCanonicalSlot(
            $payload['type_id'],
            $payload['start'],
            $payload['end'],
            $ignoreBookingId,
            (int)$payload['resource_id'],
            max(1, $partySize)
        )) {
            return null;
        }
        return $payload;
    }
}
