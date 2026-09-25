<?php
namespace Wpcb\Calendar;

final class ConnectionBusyService {
    private CalendarConnectionRepository $connections;
    private ProviderRegistry $providers;

    public function __construct(
        ?CalendarConnectionRepository $connections = null,
        ?ProviderRegistry $providers = null
    ) {
        $this->connections = $connections ?: new CalendarConnectionRepository();
        $this->providers = $providers ?: new ProviderRegistry();
    }

    /**
     * @return array<int,array{start:string,end:string,source?:string,connection_id?:int}>|\WP_Error
     */
    public function busyForResource(
        int $bookingTypeId,
        int $resourceId,
        string $fromUtc,
        string $toUtc
    ) {
        return $this->busyFromConnections(
            $this->connections->blockingForResource($resourceId, $bookingTypeId),
            $fromUtc,
            $toUtc
        );
    }

    /**
     * @return array<int,array{start:string,end:string,source?:string,connection_id?:int}>|\WP_Error
     */
    public function busyForBookingType(int $bookingTypeId, string $fromUtc, string $toUtc) {
        return $this->busyFromConnections(
            $this->connections->blockingForBookingType($bookingTypeId),
            $fromUtc,
            $toUtc
        );
    }

    /**
     * @param CalendarConnection[] $connections
     * @return array<int,array{start:string,end:string,source?:string,connection_id?:int}>|\WP_Error
     */
    private function busyFromConnections(array $connections, string $fromUtc, string $toUtc) {
        $busy = [];

        foreach ($connections as $connection) {
            $provider = $this->providers->get($connection->provider);
            if (!$provider instanceof CalendarSyncProviderInterface
                || !$this->providers->supports($connection->provider, ProviderCapabilities::BUSY_READ)
            ) {
                return new \WP_Error(
                    'wpcb_calendar_availability_unknown',
                    __('Die Kalender-Verfügbarkeit kann derzeit nicht vollständig geprüft werden. Bitte später erneut versuchen.', 'wordpress-calendar-booking')
                );
            }

            $result = $provider->busyBetween($fromUtc, $toUtc, $connection);
            if (is_wp_error($result) || !is_array($result)) {
                return new \WP_Error(
                    'wpcb_calendar_availability_unknown',
                    __('Die Kalender-Verfügbarkeit kann derzeit nicht vollständig geprüft werden. Bitte später erneut versuchen.', 'wordpress-calendar-booking')
                );
            }

            foreach ($result as $interval) {
                if (!is_array($interval) || empty($interval['start']) || empty($interval['end'])) {
                    return new \WP_Error(
                        'wpcb_calendar_availability_unknown',
                        __('Die Kalender-Verfügbarkeit kann derzeit nicht vollständig geprüft werden. Bitte später erneut versuchen.', 'wordpress-calendar-booking')
                    );
                }
                $busy[] = $interval;
            }
        }

        return $busy;
    }
}
