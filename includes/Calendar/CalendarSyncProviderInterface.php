<?php
namespace Cemb\Calendar;

interface CalendarSyncProviderInterface extends CalendarProviderInterface {
    /** @return array<int,array{start:string,end:string}>|\WP_Error */
    public function busyBetween(string $fromUtc, string $toUtc, CalendarConnection $connection);

    /** @return array|\WP_Error */
    public function createEvent(array $booking, array $meta, CalendarConnection $connection);

    /** @return array|\WP_Error */
    public function updateEvent(array $booking, array $meta, CalendarConnection $connection, string $eventId, array $providerState = []);

    /** @return array|\WP_Error */
    public function cancelEvent(CalendarConnection $connection, string $eventId, array $providerState = []);
}
