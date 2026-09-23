<?php
namespace Wpcb\Security;

use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Resources\ResourceRepository;

final class AvailabilityRequestGuard {
    public const MAX_DAYS = 60;
    public const MAX_PARTY_SIZE = 10000;
    public const MAX_RESOURCES = 25;
    public const MAX_SLOTS = 500;

    public function browserBudget(): array {
        return $this->consume(
            'browser',
            (int)apply_filters('wpcb_availability_browser_limit', 30)
        );
    }

    public function restBudget(): array {
        return $this->consume(
            'rest',
            (int)apply_filters('wpcb_availability_rest_limit', 120)
        );
    }

    /**
     * @return array{type:object,days:int,party_size:int,max_slots:int}|\WP_Error
     */
    public function validateQuery(int $typeId, int $days, int $partySize) {
        if ($typeId < 1) {
            return new \WP_Error(
                'wpcb_availability_type_required',
                __('Terminart fehlt.', 'wordpress-calendar-booking'),
                ['status' => 400]
            );
        }

        $type = (new BookingTypeRepository())->find($typeId);
        if (!$type || empty($type->is_active) || empty($type->is_public)) {
            return new \WP_Error(
                'wpcb_availability_type_missing',
                __('Terminart nicht gefunden.', 'wordpress-calendar-booking'),
                ['status' => 404]
            );
        }

        $maxDays = max(1, min(
            self::MAX_DAYS,
            (int)apply_filters('wpcb_availability_max_days', self::MAX_DAYS)
        ));
        if ($days < 1 || $days > $maxDays) {
            return new \WP_Error(
                'wpcb_availability_days_invalid',
                sprintf(
                    __('Der Verfügbarkeitszeitraum darf höchstens %d Tage umfassen.', 'wordpress-calendar-booking'),
                    $maxDays
                ),
                ['status' => 400]
            );
        }

        $maxPartySize = max(1, min(
            self::MAX_PARTY_SIZE,
            (int)($type->capacity ?? 1)
        ));
        if ($partySize < 1 || $partySize > $maxPartySize) {
            return new \WP_Error(
                'wpcb_availability_party_size_invalid',
                __('Die angefragte Gruppengröße ist für diese Terminart nicht verfügbar.', 'wordpress-calendar-booking'),
                ['status' => 400]
            );
        }

        $resources = (new ResourceRepository())->forBookingType($typeId, true);
        $maxResources = max(1, min(
            250,
            (int)apply_filters('wpcb_availability_max_resources', self::MAX_RESOURCES)
        ));
        if (count($resources) > $maxResources) {
            return new \WP_Error(
                'wpcb_availability_resource_fanout',
                __('Für diese Terminart sind zu viele Ressourcen gleichzeitig konfiguriert.', 'wordpress-calendar-booking'),
                ['status' => 422]
            );
        }

        return [
            'type' => $type,
            'days' => $days,
            'party_size' => $partySize,
            'max_slots' => $this->maxSlots(),
        ];
    }

    public function maxSlots(): int {
        return max(1, min(
            2000,
            (int)apply_filters('wpcb_availability_max_slots', self::MAX_SLOTS)
        ));
    }

    public function transientKeyForCurrentClient(string $scope): string {
        $scope = sanitize_key($scope);
        if ($scope === '') {
            $scope = 'public';
        }

        $remote = isset($_SERVER['REMOTE_ADDR'])
            ? trim((string)wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';
        if ($remote === '' || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            $remote = 'unknown';
        }

        $pseudonym = hash_hmac(
            'sha256',
            $scope . '|' . $remote,
            wp_salt('auth')
        );

        return 'wpcb_availability_' . $scope . '_' . substr($pseudonym, 0, 40);
    }

    private function consume(string $scope, int $limit): array {
        $limit = max(1, min(10000, $limit));
        $window = max(1, min(
            DAY_IN_SECONDS,
            (int)apply_filters('wpcb_availability_window_seconds', MINUTE_IN_SECONDS, $scope)
        ));
        $key = $this->transientKeyForCurrentClient($scope);
        $now = time();
        $state = get_transient($key);

        if (!is_array($state) || (int)($state['reset_at'] ?? 0) <= $now) {
            $state = [
                'count' => 0,
                'reset_at' => $now + $window,
            ];
        }

        $count = max(0, (int)($state['count'] ?? 0));
        $resetAt = max($now + 1, (int)($state['reset_at'] ?? ($now + $window)));

        if ($count >= $limit) {
            return [
                'allowed' => false,
                'limit' => $limit,
                'remaining' => 0,
                'retry_after' => max(1, $resetAt - $now),
                'reset_at' => $resetAt,
            ];
        }

        $count++;
        $state['count'] = $count;
        $state['reset_at'] = $resetAt;
        set_transient($key, $state, max(1, $resetAt - $now));

        return [
            'allowed' => true,
            'limit' => $limit,
            'remaining' => max(0, $limit - $count),
            'retry_after' => 0,
            'reset_at' => $resetAt,
        ];
    }
}
