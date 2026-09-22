# Architecture

## Core rule

The booking domain owns availability. Calendar providers only contribute busy intervals and optional write-back events.

## Provider contract

A calendar connection advertises capabilities instead of being hard-coded as iCloud.

```php
interface CalendarProviderInterface {
    public function capabilities(): array;
    public function busyBetween(DateTimeImmutable $from, DateTimeImmutable $to, CalendarConnection $connection): array;
    public function createEvent(Booking $booking, CalendarConnection $connection): SyncResult;
    public function updateEvent(Booking $booking, CalendarConnection $connection): SyncResult;
    public function cancelEvent(Booking $booking, CalendarConnection $connection): SyncResult;
}
```

Initial adapters:

- `IcsFeedProvider`: read-only ICS/webcal source.
- `CalDavProvider`: generic CalDAV availability and event write-back.
- `IcloudCalDavPreset`: Apple-specific discovery/configuration on top of CalDAV.
- `GoogleCalendarProvider`: OAuth + FreeBusy/Events API.
- `MicrosoftGraphProvider`: OAuth + Graph availability/events with account-type-specific behavior.

## Availability pipeline

1. Resolve booking type/resource and requested presentation time zone.
2. Generate canonical candidate slots from weekly rules and exceptions using `DateTimeImmutable`.
3. Normalize instants to UTC for storage/comparison.
4. Merge busy intervals from internal reservations/confirmed bookings and all blocking provider connections.
5. Apply buffers, notice and horizon rules.
6. Return signed, short-lived slot tokens rather than trusting a client-supplied timestamp.
7. On submit: verify token, acquire resource/day lock, regenerate/revalidate the slot, insert reservation inside the same critical section, release lock.

## Booking state machine

Suggested states:

- `reserved_unconfirmed`
- `pending_approval`
- `confirmed`
- `rejected`
- `cancelled`
- `expired`

Transitions must be explicit and whitelisted. No arbitrary status string writes.

## Time model

- Store instants in UTC.
- Store IANA time-zone names such as `Europe/Vienna`, never only fixed UTC offsets.
- Display in visitor-selected time zone when enabled; otherwise use the site booking time zone.
- Use `DateTimeImmutable`; avoid mixed `strtotime()`, `date()` and WordPress local timestamps in domain logic.

## UI architecture

One render model feeds multiple adapters:

- `YoothemeThemeAdapter`: detects YOOtheme Pro, does not enqueue duplicate UIkit, registers native Builder elements and uses theme/UIkit classes.
- `UikitFallbackAdapter`: enqueues a locally bundled, pinned UIkit build and plugin component CSS/JS only when needed.
- `WordPressBlockAdapter`: exposes Gutenberg blocks while reusing the same render model.

Do not scrape arbitrary themes and copy their CSS class names. That is brittle and impossible to guarantee across updates. Instead expose filters for wrapper/button/form classes, CSS variables and render hooks so themes can integrate intentionally.

## Privacy model

Public output defaults to availability/busy status only. External event summaries, locations, attendee data and customer identity are private unless an administrator explicitly opts into a display field.

## Queue model

Jobs are idempotent and leased:

- `pending -> running(lease_until) -> done`
- stale running jobs are reclaimable after lease expiry
- retries use backoff and max attempts
- create/update/cancel are deduplicated by booking + destination + desired state/version

## Notification model

Every notification has an idempotency key. Reminder delivery records prevent repeated hourly cron runs from resending the same reminder.
