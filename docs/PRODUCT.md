# WordPress Calendar Booking 2.0 — Product Definition

## Product goal

A privacy-conscious WordPress appointment booking plugin that combines configurable availability with external calendar busy-time blocking and optional event write-back.

The product must remain useful without any third-party calendar connection. External providers are adapters, not the source of truth for booking rules.

## Primary user flows

1. Administrator creates one or more booking types with duration, buffers, working hours, exceptions, minimum notice and booking horizon.
2. Administrator optionally connects external calendars that block availability and optionally receive confirmed bookings.
3. Visitor selects a booking type, sees only server-generated available slots in the selected time zone, completes the form and submits.
4. The server revalidates and atomically reserves the slot.
5. Visitor confirms their email address (double opt-in).
6. Depending on booking type/settings, the booking is automatically confirmed or moves to admin approval.
7. Confirmation, reschedule and cancellation notifications are sent with standards-compliant calendar data.
8. External calendar write-back runs idempotently through a retryable queue.

## Non-negotiable release properties

- A client cannot book a time that was not generated as a valid slot by the server.
- Two concurrent requests cannot create overlapping confirmed/reserved bookings for the same resource.
- Recurring external events and time zones cannot silently create false availability.
- Public calendar UI is busy-only by default; private event/customer data is never exposed by default.
- State-changing links such as cancellation require an explicit confirmation POST.
- External calendar credentials are stored using authenticated encryption; insecure plaintext/base64 fallback is prohibited.
- Personal data supports WordPress privacy export/erase and configurable retention/anonymization.
- External service connections are opt-in and documented.
- Core booking works when YOOtheme Pro is absent.

## Scope for 2.0.0

### Booking core
- Booking types, duration and buffers
- Weekly availability rules
- Date/time exceptions and holidays
- Minimum notice and maximum booking horizon
- Double opt-in
- Optional admin approval
- Cancel/reschedule confirmation flows
- Reminder/notification idempotency
- CSV export for administrators
- Explicit booking state machine and audit history

### Calendar providers
- Internal bookings
- Public ICS feed (read-only, recurrence-capable)
- Generic CalDAV free/busy + write adapter
- iCloud preset on top of CalDAV

Google Calendar and Microsoft Graph are designed into the provider contract but may ship in 2.1 if OAuth work would delay the reliable booking core.

### Frontend
- Shared semantic component layer with UIkit classes
- YOOtheme Pro adapter: use existing UIkit/theme assets and native Builder elements
- Generic fallback adapter: locally bundled pinned UIkit assets
- Shortcodes retained for compatibility
- Gutenberg block(s) for new installations
- Busy-only public calendar by default
- Accessible keyboard/focus behavior and screen-reader labels
- Visitor time-zone selection/detection with clear site-time-zone fallback

## Deliberately later

- Payments
- Group/capacity booking
- Staff/resource scheduling beyond one logical resource
- Waiting lists
- Video meeting creation
- Customer accounts/dashboard
- Recurring customer bookings

These are valuable but should not expand the first public architecture before correctness, privacy and calendar reliability are proven.
