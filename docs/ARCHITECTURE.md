# Architecture

## Naming convention

The canonical product name is **WordPress Calendar Booking** and the canonical plugin slug/text domain is `wordpress-calendar-booking`.

Technical identifiers use one derived prefix consistently:

- PHP namespace: `Wpcb\\...`
- PHP constants: `WPCB_*`
- WordPress hooks, options, cron events and database tables: `wpcb_*`
- frontend handles, CSS classes and data attributes: `wpcb-*`
- Gutenberg block namespace: `wpcb/*`
- shortcodes: `wpcb_*`
- main plugin file: `wordpress-calendar-booking.php`

Do not introduce a second product abbreviation or identifier prefix.

## Core rule

The booking domain owns availability, reservation, capacity and lifecycle state. External calendars, payments, meeting providers and webhook consumers are adapters around that domain; they do not become the source of truth.

## Booking/resource model

A booking type defines customer-facing duration, buffers, notice/horizon, capacity/payment behavior and optional recurring-series behavior. Booking types are assigned to one or more active resources/staff records.

Resources own the conflict domain:

- weekly availability and exceptions may be resource-scoped
- calendar connections can be routed per resource
- a generated slot identifies the booking type, resource and canonical UTC interval
- internal reservation checks operate on the selected resource
- capacity is evaluated as the sum of active reserved/confirmed party sizes for the same resource interval

A fresh installation can model the traditional one-calendar use case with a single default resource, but concurrency is no longer site-wide.

## Availability pipeline

1. Resolve booking type, eligible resource(s), requested party size and presentation time zone.
2. Generate candidate local wall-clock slots from weekly rules and exceptions.
3. Normalize candidate instants to UTC while retaining IANA time-zone semantics.
4. Merge internal reserved/confirmed occupancy with busy intervals from blocking calendar connections routed to that resource.
5. Apply buffers, capacity, notice and horizon rules.
6. Return short-lived HMAC-signed slot tokens. A token binds booking type, resource, canonical start/end and expiry and contains no personal data.
7. On submit, verify the token and regenerate/revalidate the candidate.
8. Acquire the resource-scoped MySQL advisory reservation lock, repeat the authoritative availability/capacity check, insert the reservation, then release the lock.

The second check inside the lock is authoritative. Expired unconfirmed reservations stop consuming capacity after `reserved_until`.

## Reservation and capacity concurrency

The lock key is derived from the resource conflict domain rather than a single global site lock. Independent resources can therefore reserve concurrently while requests for the same resource remain serialized around the final check/insert window.

Capacity one behaves like exclusive booking. For larger capacities, party sizes are summed atomically under the same resource lock. Cancellation, rejection, expiry and rescheduling release the corresponding occupancy before later availability calculations.

## Booking state machine

Canonical states:

- `reserved_unconfirmed`
- `pending_approval`
- `confirmed`
- `rejected`
- `cancelled`
- `expired`

Callers emit semantic lifecycle events instead of assigning target status directly. Transition writes are compare-and-swap guarded by the expected current state. Repeating an already-applied transition is idempotent and must not emit duplicate side effects.

Rescheduling changes canonical slot/resource data while preserving the lifecycle status where valid. Confirm/reschedule paths revalidate current availability before committing.

Audit rows record technical lifecycle event, actor, state and time with generic notes rather than duplicated customer identity.

## Recurring booking series

Recurring customer bookings are stored as a bounded series of normal occurrence bookings linked by a series identifier. Supported recurrence is intentionally explicit and bounded by the booking horizon.

Series creation validates every occurrence before any occurrence is committed and then reserves the series atomically enough to avoid partial domain state. Local wall-clock recurrence is preserved across UTC-offset changes; ambiguous or non-existent DST wall times are rejected.

Lifecycle operations can target one occurrence or the selected occurrence plus all remaining occurrences. Paid series remain excluded until a series-wide payment contract is explicitly defined.

## Provider contract and calendar routing

Calendar providers advertise capabilities rather than leaking provider-specific branches into booking-domain code. The registry normalizes capabilities such as:

- `busy_read`
- `event_create`
- `event_update`
- `event_cancel`
- `calendar_discovery`

Implemented adapters cover ICS/webcal, generic CalDAV, iCloud, Google Calendar and Microsoft Graph.

Connections store provider identity, selected calendar, active/blocking/write-back flags, non-secret configuration, encrypted credentials and health metadata. Credentials are deliberately excluded from normal repository reads and decrypted only at point of use.

Booking-type/resource mappings determine which external calendars block availability and which receive write-back.

## Queue and side effects

Long-running or failure-prone side effects use leased/idempotent jobs:

- calendar create/update/cancel
- notification delivery/reminders
- webhook delivery/retry
- video-meeting create/update/cancel
- related maintenance tasks

Jobs follow `pending -> running(lease_until) -> done`; expired leases are reclaimable. Retry/backoff is bounded, and logical operations use idempotency keys so retries cannot create duplicate external state.

## Public email-link actions

Email/security scanners may prefetch links. Therefore `GET ?wpcb_action=...&wpcb_token=...` only validates enough information to render a confirmation/status screen and never mutates a booking.

Mutations post to `admin-post.php` and require:

- expected action-specific one-time token
- nonce bound to action + token
- allowed current lifecycle state
- fresh canonical slot validation for rescheduling
- serialized token consumption

The one-time token is marked used only after the domain action succeeds.

## Customer portal

The customer portal does not require WordPress customer accounts. A customer requests a one-time magic link by email, confirms it through scanner-safe POST and receives an encrypted HttpOnly portal session scoped to the verified email.

Portal reads are constrained to that customer identity. Mutations use per-session CSRF tokens and reuse booking lifecycle services rather than bypassing domain validation. Privacy erasure revokes matching sessions.

## REST API

The versioned `wpcb/v1` REST namespace exposes privacy-safe public booking/availability data and authenticated administrative/integration operations.

Mutations require explicit permission callbacks, schema validation and idempotency keys. API responses do not expose provider credentials, internal secrets, private calendar payloads or unrelated customer data.

## Outbound webhooks

Lifecycle webhooks are schema-versioned queued deliveries. Each delivery has a stable event identifier, bounded retry policy and HMAC signature over timestamp plus raw payload.

Endpoint secrets are encrypted at rest. Delivery history stores technical state and redacted errors rather than secrets or unnecessary customer content.

## Payments

Payment state is separate from booking state and linked by technical identifiers. A payment adapter receives only the minimum technical data needed for the transaction.

Provider callbacks are idempotent. Pending-payment expiry can release an unconfirmed reservation. Refund/cancellation mapping is explicit. Raw PAN, CVC/CVV, bank credentials and full sensitive provider payloads are never stored.

## Waiting lists

Waiting-list entries are scoped to booking type/resource/slot semantics and retain only required customer data. When capacity is released, promotion runs through a serialized offer process so one released seat cannot produce multiple active holds.

Offers expire and return capacity to the queue. Confirmation uses one-time tokens and the normal canonical booking path; customers cannot inspect other waiting-list entries.

## Video meetings

Meeting integrations use a provider-neutral capability contract for Zoom, Google Meet and Microsoft Teams. Credentials are encrypted using the shared secret infrastructure.

Meeting create/update/cancel operations are queued and idempotent. Join URLs are customer/admin communication data and are never part of public availability output.

## Time model

- store instants in UTC
- store/use IANA zone names, never fixed-offset-only domain state
- preserve local wall-clock semantics where recurrence requires it
- use `DateTimeImmutable`
- reject ambiguous/non-existent recurrence wall times rather than silently shifting them

## UI architecture

One shared render model feeds:

- YOOtheme Pro native Builder elements without duplicate UIkit
- pinned local UIkit fallback
- dynamic Gutenberg blocks
- shortcodes
- customer portal surfaces

Themes integrate through filters, wrapper/button/form classes, CSS variables and render hooks. The plugin does not scrape arbitrary theme markup/classes.

## Privacy model

Public output defaults to availability/capacity-safe information only. External calendar summaries, locations, attendees, customer identity, booking notes, payment internals and meeting URLs are private unless a specific product surface is explicitly designed to expose them to the authorized party.

WordPress privacy exporter/eraser and retention/anonymization operate across booking/customer data and related portal/session records. Durable operational history is minimized and secrets are excluded.

## Operations and release architecture

WP-Cron drives retryable scheduled processing; production sites should use a real cron runner. Systemstatus exposes scheduler health, queue state and privacy-safe mail diagnostics.

Stable releases are built as reproducible ZIP artifacts and gated on PHP syntax, dependency audits, translation catalog generation, WordPress integration tests, fresh installation, imported migration fixtures, uninstall policy and packaged Chromium browser acceptance.

See [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md), [OPERATIONS.md](OPERATIONS.md), [PRODUCT.md](PRODUCT.md) and [ROADMAP.md](ROADMAP.md).
