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
     * @return array<int,array{start:string,end:string,source?:string,connection_id?:int}>
     */
    public function busyForBookingType(int $bookingTypeId, string $fromUtc, string $toUtc): array {
        $busy = [];

        foreach ($this->connections->blockingForBookingType($bookingTypeId) as $connection) {
            $provider = $this->providers->get($connection->provider);
            if (!$provider instanceof CalendarSyncProviderInterface
                || !$this->providers->supports($connection->provider, ProviderCapabilities::BUSY_READ)
            ) {
                continue;
            }

            $result = $provider->busyBetween($fromUtc, $toUtc, $connection);
            if (is_wp_error($result)) {
                continue;
            }

            foreach ($result as $interval) {
                if (!is_array($interval) || empty($interval['start']) || empty($interval['end'])) {
                    continue;
                }
                $busy[] = $interval;
            }
        }

        return $busy;
    }
}
