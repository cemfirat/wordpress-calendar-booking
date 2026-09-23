# Historical GitHub Issue Backlog

> **Archived snapshot.** This file records the backlog used to build the original reliable-booking and provider foundation. It is not the current roadmap. For shipped 3.x phases see [ROADMAP.md](ROADMAP.md); for the current product surface see [PRODUCT.md](PRODUCT.md).

## Original release blockers

The initial implementation focused on these invariants:

1. canonical slot tokens and server-side availability validation
2. atomic reservation and double-booking protection
3. UTC/IANA time-zone model and DST tests
4. recurrence-capable ICS/CalDAV busy-time handling
5. busy-only public calendar privacy defaults
6. explicit booking state machine
7. safe cancel/reschedule/Double-Opt-In confirmation
8. indexed selector/verifier tokens
9. authenticated encryption for provider credentials
10. leased queues and idempotent notifications/sync
11. WordPress privacy tools and retention
12. shared UIkit/YOOtheme frontend adapter

## Original provider/admin expansion

The next implementation phase added the calendar connection model, Google Calendar, Microsoft Graph, generic CalDAV/iCloud, provider diagnostics, CSV export, scheduler health, notification delivery history and lifecycle audit history.

## Capabilities subsequently shipped in 3.x

The items that were once listed as future work are now implemented:

- resource/staff scheduling
- group/capacity bookings
- versioned REST API and signed outbound webhooks
- secure passwordless customer portal
- provider-neutral payment lifecycle foundation
- waiting lists with safe capacity promotion
- Zoom/Google Meet/Microsoft Teams meeting orchestration
- bounded recurring customer booking series
- packaged browser release acceptance
- dependency-audit, uninstall, i18n, setup-readiness and mail-diagnostic hardening

Do not add new work to this archive. Create focused GitHub issues and update [ROADMAP.md](ROADMAP.md) when a new release phase is planned.
