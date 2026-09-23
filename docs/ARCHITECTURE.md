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

## Domain boundaries

The booking domain owns availability, reservation/capacity decisions and lifecycle state. Optional adapters are deliberately downstream:

- calendar providers contribute busy intervals and optional event write-back;
- payment adapters contribute payment state, never slot truth;
- video-meeting providers create/update/delete remote meetings from booking lifecycle effects;
- webhooks publish privacy-safe lifecycle events;
- mail transports deliver notifications;
- the customer portal and REST API invoke the same domain services used by wp-admin/public flows.

No adapter may bypass canonical availability or booking transitions.

## Resource and capacity model

Booking types can map to one or more resources/staff through `wpcb_booking_type_resources`. Resources carry active/public metadata and capacity; booking types can further constrain capacity and public remaining-seat display.

Availability may be global, booking-type scoped or resource scoped. External calendar connections are routed per booking type/resource configuration so a provider blocks only the resources it is intended to represent.

### Reservation concurrency

Reservation writers are serialized **per resource** using `ResourceLock`. Independent resources therefore reserve concurrently.

The reservation path is:

1. Resolve and validate the signed slot token, booking type, resource and requested party size.
2. Acquire the advisory lock for the selected resource.
3. Re-resolve the slot while the lock is held.
4. Recalculate occupied seats/capacity for the interval.
5. Insert the unconfirmed reservation only if capacity still fits.
6. Create any configured payment obligation.
7. Release the resource lock in a `finally` path.

The second availability/capacity check is authoritative. Expired unconfirmed reservations stop blocking once their reservation TTL is exceeded. Legacy/unscoped bookings are treated conservatively by conflict queries until assigned.

## Availability pipeline

1. Resolve booking type, eligible resources and requested presentation time zone.
2. Generate candidate slots from weekly rules and exceptions using immutable date/time objects.
3. Normalize instants to UTC for storage and comparison.
4. Merge internal blocking bookings with resource-routed external busy intervals.
5. Apply buffers, notice, booking horizon and capacity.
6. Return signed, short-lived canonical slot tokens containing no personal data.
7. Revalidate after acquiring the resource lock during reservation or reschedule.

External event summaries, attendee data and locations are never required to make a public availability decision.

## Booking lifecycle

Canonical booking states include:

- `reserved_unconfirmed`
- `pending_approval`
- `confirmed`
- `rejected`
- `cancelled`
- `expired`

Callers emit semantic lifecycle events rather than writing target states directly. Transition writes are compare-and-swap guarded by the expected current state. Successful effects are designed to remain idempotent when queues retry.

Rescheduling changes position while preserving the appropriate lifecycle state. Confirmation and reschedule paths revalidate current availability/capacity before committing.

Audit history records booking ID, state/event/actor/time and generic notes; it does not duplicate customer identity.

## Public tokens and state-changing links

Public email links are safe to prefetch. GET requests inspect a one-time token and render status/confirmation UI; they do not mutate bookings.

Mutation requires a protected POST with:

- the expected action-specific selector/verifier token;
- a nonce bound to the action;
- a lifecycle state that allows the event;
- canonical server-side validation where a slot changes;
- the relevant lock/atomicity rules.

Tokens are consumed only after the domain action succeeds.

## Time and recurrence

- Store booking-domain instants in UTC.
- Store IANA time-zone identifiers rather than fixed offsets.
- Render in the visitor-selected time zone when enabled, otherwise the configured booking time zone.
- Recurring customer series preserve local wall-clock cadence across offset/DST changes.
- Recurrence expansion is bounded by occurrence count and booking horizon.
- External iCalendar recurrence expansion is bounded to the requested availability window.

## Calendar provider architecture

Providers register through the provider registry and advertise capabilities such as busy read, event create/update/cancel and calendar discovery.

Implemented adapters include public ICS, generic CalDAV/iCloud, Google Calendar and Microsoft Graph. Connection records store non-secret configuration separately from authenticated-encrypted credentials. Ordinary repository reads do not expose credential payloads.

Booking/resource routing is separate from provider implementation so domain services do not contain Google/Microsoft/Apple-specific branching.

## Queue and delivery model

Background work uses leased jobs:

- pending jobs can be claimed by one worker;
- running jobs carry a lease and stale work can be reclaimed;
- retries use bounded attempts/backoff;
- idempotency keys prevent duplicate logical side effects.

The queue dispatches calendar synchronization, webhook delivery, notification-related work and video-meeting jobs. Delivery/audit tables store technical status and redacted errors rather than message bodies, customer payload copies or secrets.

## REST API

The versioned REST namespace is `wpcb/v1`.

Public routes expose only privacy-safe booking types, explicitly public resource labels and availability. Administrative booking and integration routes require WordPress capabilities and validate request schemas. Mutation paths use idempotency support where external retries are expected.

The REST API is an adapter around domain services; it is not a second booking engine and does not write tables directly to bypass lifecycle rules.

## Outbound webhooks

Webhook endpoint secrets are encrypted at rest and only returned at creation/rotation boundaries. Outbound lifecycle payloads have stable event/delivery identifiers and a schema version, are signed with HMAC-SHA256 and are queued through the leased retry system.

Endpoints require valid HTTPS URLs. Delivery history is redacted and does not expose signing secrets.

## Customer portal

The customer portal uses short-lived/revocable authentication material and authorizes every lookup against the authenticated customer identity. Self-service cancellation/rescheduling invokes the canonical booking lifecycle services. Portal UI never exposes another customer's booking or administrator-only audit/provider data.

## Payments

Payment handling is provider-neutral. A booking may have a payment obligation with amount, currency, provider reference and explicit payment state. Provider callbacks are idempotent and cannot attach a payment to a different booking.

Raw PAN/CVC or equivalent card credentials are not stored by the plugin. Expired/abandoned payment obligations integrate with reservation expiry so held capacity can be released safely.

A paid recurring series uses one payment obligation owned by its first occurrence. The amount is computed server-side as the booking-type unit price multiplied by the bounded occurrence count; the stored payment amount/currency are the immutable price snapshot. Every occurrence resolves to that same payment for confirmation gating and customer display. Payment expiry releases the complete reserved series. Authenticated portal sessions can resume an open provider Checkout session without creating another payment obligation; expired provider sessions are replaced under serialized checkout preparation. Cancellation can refund one occurrence or the selected occurrence plus the remaining series. Refund amounts are derived deterministically from the immutable original amount in integer minor units, cumulative queued/refunded amounts are tracked atomically, and provider refunds use stable idempotency keys so retries cannot over-refund.

## Waiting lists

Waiting-list entries are scoped to booking type/resource/slot and requested party size. When capacity is released, promotion uses a bounded hold rather than immediately creating an over-capacity booking. Only one active offer can own released capacity, and expired offers return it to the promotion process.

Waiting-list identity is private and participates in export/erase/retention handling.

## Video meetings

Video meeting providers implement a shared capability contract. Current adapters cover Zoom, Google Meet and Microsoft Teams with provider-specific create/update/delete support.

Lifecycle effects enqueue meeting creation/update/deletion idempotently. Credentials use the shared encrypted-secret infrastructure. Join URLs are treated as access credentials: they are included only in appropriate customer/admin communication, excluded from public availability, and removed locally during privacy erasure.

## Recurring booking series

Recurring customer bookings are represented as a series plus normal per-occurrence booking rows. Series creation validates every bounded occurrence against resource availability/capacity before commitment.

Series operations distinguish:

- a single occurrence;
- remaining occurrences from a chosen point.

Per-occurrence lifecycle/audit semantics remain intact. Calendar write-back and notifications operate through the same idempotent effect layer as single bookings. Paid series additionally share one series-level payment state while retaining per-occurrence privacy-safe payment audit events; cancellation scopes map to deterministic refund allocations without copying customer data into payment audit context.

## Frontend architecture

One semantic component/render layer serves:

- YOOtheme Pro native Builder elements using the site's existing UIkit runtime;
- shortcodes;
- dynamic Gutenberg Booking Form and Availability Calendar blocks;
- a pinned local UIkit fallback when no compatible theme runtime is present.

Editor previews are read-only. Live booking mutations happen only on the frontend/domain endpoints. Themes integrate through filters/hooks and CSS variables rather than copied theme-specific markup.

## Privacy and secret handling

Public output is availability/busy-only by default. Customer identity, external event details, payment internals, provider credentials, webhook secrets and meeting access URLs are excluded unless a specific authenticated workflow requires them.

WordPress privacy export/erase and configured retention cover booking/customer-owned data. Diagnostics and audit logs prefer identifiers, timestamps, state and redacted error summaries over payload copies.

Authenticated encryption is required for stored provider credentials/secrets. There is no plaintext/base64 fallback.

## Operations and release architecture

Operational surfaces include scheduler health, delivery logs, provider diagnostics, mail-transport diagnostics, lifecycle audit history and first-run readiness.

Stable release ZIPs are reproducibly packaged and gated by:

- PHP syntax across supported versions;
- WordPress integration matrices;
- fresh-install and legacy-migration checks;
- browser acceptance against the packaged plugin;
- uninstall-policy checks;
- dependency security audits;
- translation catalog generation.

See [OPERATIONS.md](OPERATIONS.md), [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md), [FIRST-RUN.md](FIRST-RUN.md) and [ROADMAP.md](ROADMAP.md).
