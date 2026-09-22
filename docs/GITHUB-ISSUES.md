# Initial GitHub Issue Backlog

Do not publish detailed exploit reproduction for security-sensitive items until the affected public version is fixed. Public issues describe the invariant and acceptance criteria without exposing a weaponized request.

## P0 — release blockers

1. Canonical slot tokens and server-side availability validation
2. Atomic reservation to prevent double bookings
3. UTC/IANA timezone domain model and DST tests
4. Recurrence-capable ICS/CalDAV busy-time handling
5. Busy-only public calendar privacy defaults
6. Explicit booking state machine
7. Safe cancel/reschedule/DOI confirmation flow
8. Indexed selector/verifier tokens
9. Authenticated encryption for provider credentials
10. Queue leases and idempotent notifications/sync
11. WordPress privacy tools and retention
12. UIkit/YOOtheme frontend adapter

## P1 — calendar providers

- Calendar provider/connection data model
- Google Calendar OAuth + FreeBusy + Events
- Microsoft Graph provider
- Generic CalDAV provider + iCloud preset
- Provider health and diagnostics

## P1 — product/admin

- CSV booking export and filters
- Scheduler health
- Notification delivery log

## P2 — later

Resources/staff, group/capacity bookings, webhooks/API, Zoom/Meet/Teams, payments, waiting list, customer portal and recurring customer bookings.
