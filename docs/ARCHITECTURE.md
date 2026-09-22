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

## Calendar connection model

Provider adapters are registered through the `cemb_calendar_providers` filter and implement `CalendarProviderInterface`. The registry normalizes capability identifiers so booking-domain code can ask for behavior without knowing Google, Microsoft, Apple or CalDAV details.

Canonical capabilities:

- `busy_read`
- `event_create`
- `event_update`
- `event_cancel`
- `calendar_discovery`

Connections are stored in `cemb_calendar_connections` with:

- provider identifier
- administrator-facing connection name
- selected remote calendar identifier
- active/blocking/write-back flags
- non-secret provider configuration
- authenticated-encrypted credential/token payload
- health status plus last success/error metadata

Credentials are deliberately excluded from normal `find()`/`all()` connection reads. Provider code must request them explicitly through the connection repository, which decrypts the authenticated payload only at the point of use.

Booking-type routing lives in `cemb_booking_type_calendar_connections`. A booking type can use multiple connections, and each mapping independently controls whether that connection blocks availability and/or receives confirmed booking write-back. This keeps external account topology out of the booking state machine.

## Availability pipeline

1. Resolve booking type/resource and requested presentation time zone.
2. Generate canonical candidate slots from weekly rules and exceptions using `DateTimeImmutable`.
3. Normalize instants to UTC for storage/comparison.
4. Merge busy intervals from internal reservations/confirmed bookings and all blocking provider connections.
5. Apply buffers, notice and horizon rules.
6. Return HMAC-signed, short-lived slot tokens rather than trusting a client-supplied timestamp. Tokens bind booking type, canonical start/end and expiry and contain no personal data. They may be replayed during their short TTL, so the signature is never treated as a reservation.
7. On submit: verify token and regenerate/revalidate the slot, acquire a MySQL advisory reservation lock, re-check availability while holding the lock, insert the unconfirmed reservation, then release the lock. The 2.0 single-resource model deliberately uses one conservative site-wide lock; a later resource model can narrow the lock key without weakening the invariant.

## Reservation concurrency

The initial reservation path performs a fast pre-check, then obtains a MySQL advisory lock and repeats the canonical slot check while serialized. Only the second check is authoritative. Unconfirmed reservations block availability only until `reserved_until`; an expired reservation no longer keeps a slot unavailable.

## Booking state machine

Canonical states:

- `reserved_unconfirmed`
- `pending_approval`
- `confirmed`
- `rejected`
- `cancelled`
- `expired`

Callers emit semantic lifecycle events instead of target status strings:

| Event | From | To |
| --- | --- | --- |
| `email_confirmed_approval` | `reserved_unconfirmed` | `pending_approval` |
| `email_confirmed_automatic` | `reserved_unconfirmed` | `confirmed` |
| `admin_approved` | `pending_approval` | `confirmed` |
| `admin_rejected` | `pending_approval` | `rejected` |
| `user_cancelled` | `pending_approval`, `confirmed` | `cancelled` |
| `admin_cancelled` | `pending_approval`, `confirmed` | `cancelled` |
| `reservation_expired` | `reserved_unconfirmed` | `expired` |

The transition write is compare-and-swap guarded by the expected current state. Repeating the same successful transition is idempotent and does not fire side effects twice. Any transition into `confirmed` and all Double-Opt-In confirmation events revalidate current slot availability before committing.

Rescheduling is a lifecycle event, not a status. A `confirmed` booking remains `confirmed`; a `pending_approval` booking remains `pending_approval`. Reschedule writes are also guarded by the expected state.

Mail and calendar write-back are subscribed to lifecycle events after the database transition. Audit rows record state/event/actor/time and generic notes, not customer identity.

## Public email-link actions

Email links are safe to prefetch. `GET ?cemb_action=...&cemb_token=...` only inspects the token and renders a confirmation/status screen; it never changes a booking.

Mutating actions post to `admin-post.php` and require all of:

- the expected action-specific one-time token
- a WordPress nonce bound to action + token
- the current lifecycle state to allow the requested event
- canonical server-side slot revalidation for rescheduling
- a short MySQL advisory lock around token verification/action/consumption

The one-time token is marked used only after the domain action succeeds. Failed CSRF, invalid slot or domain-transition checks leave booking state unchanged. Used and expired tokens remain readable as non-destructive status screens.

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
