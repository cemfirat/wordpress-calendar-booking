<?php
namespace Wpcb\Calendar;

final class CalendarConnection {
    public int $id;
    public string $provider;
    public string $name;
    public string $remoteCalendarId;
    public bool $blocksAvailability;
    public bool $receivesBookings;
    public bool $active;
    public string $healthStatus;
    public ?string $lastSuccessAt;
    public ?string $lastReadAt;
    public ?string $lastWriteAt;
    public ?string $lastErrorAt;
    public string $lastErrorMessage;
    public string $createdAt;
    public string $updatedAt;

    public static function fromRow(object $row): self {
        $connection = new self();
        $connection->id = (int)$row->id;
        $connection->provider = (string)$row->provider;
        $connection->name = (string)$row->name;
        $connection->remoteCalendarId = (string)($row->remote_calendar_id ?? '');
        $connection->blocksAvailability = (bool)$row->blocks_availability;
        $connection->receivesBookings = (bool)$row->receives_bookings;
        $connection->active = (bool)$row->is_active;
        $connection->healthStatus = (string)($row->health_status ?? 'unknown');
        $connection->lastSuccessAt = !empty($row->last_success_at) ? (string)$row->last_success_at : null;
        $connection->lastReadAt = !empty($row->last_read_at) ? (string)$row->last_read_at : null;
        $connection->lastWriteAt = !empty($row->last_write_at) ? (string)$row->last_write_at : null;
        $connection->lastErrorAt = !empty($row->last_error_at) ? (string)$row->last_error_at : null;
        $connection->lastErrorMessage = (string)($row->last_error_message ?? '');
        $connection->createdAt = (string)$row->created_at;
        $connection->updatedAt = (string)$row->updated_at;
        return $connection;
    }
}
