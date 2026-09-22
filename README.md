# WordPress Calendar Booking

Privacy-conscious appointment booking for WordPress with configurable availability, Double Opt-In, optional admin approval, calendar blocking/write-back, ICS attachments, UIkit components and YOOtheme Pro integration.

> **Development status:** 2.0 is under active development. The imported 1.x prototype is not considered a stable public release. Security, privacy, recurrence and concurrency issues tracked in GitHub Issues are release blockers.

## Product principles

- Booking rules live in WordPress; external calendars only contribute busy intervals and optional write-back.
- The server generates and validates bookable slots. Browser-provided timestamps are never trusted as availability proof.
- Public calendar output is busy-only: external event details and customer booking details are excluded from the public view model.
- Calendar connections are opt-in and request the minimum useful permissions.
- YOOtheme Pro uses its existing UIkit/theme system. Without YOOtheme, the plugin uses a locally bundled UIkit fallback only where booking components are rendered.
- Core booking works without Google, Microsoft, Apple or any other third-party account.

## Calendar providers

- Public ICS / webcal feed — read-only busy blocking
- Generic CalDAV — busy blocking and optional write-back
- iCloud — Apple-focused CalDAV connection preset
- Google Calendar — OAuth + FreeBusy + Events API
- Microsoft 365 / Outlook — Microsoft Graph OAuth, account-type-aware availability and event write-back

### Microsoft Graph behavior

For delegated Microsoft accounts the plugin requests `Calendars.ReadBasic` when a connection only blocks availability and `Calendars.ReadWrite` when write-back is enabled. Work/school default calendars use Graph `getSchedule` when available. Personal Microsoft accounts and specific calendar IDs use the supported `calendarView` path instead. OAuth credentials are encrypted at rest through the shared connection repository.

## Booking flow

1. Administrator configures booking types, duration, buffers, weekly rules, exceptions, notice and horizon.
2. Optional calendar connections add busy intervals.
3. Visitor receives server-generated available slots in the selected time zone.
4. Submission revalidates and atomically reserves the slot.
5. Double Opt-In confirms the visitor email.
6. Optional admin approval confirms or rejects the booking.
7. Notifications include standards-compliant calendar data.
8. Calendar write-back is queued, idempotent and retryable.

## Frontend architecture

One semantic component/render layer serves:

- YOOtheme Pro native Builder elements when YOOtheme is installed
- Shortcodes for compatibility
- WordPress blocks are tracked as a dedicated follow-up
- UIkit fallback assets when no compatible UIkit/YOOtheme runtime is present

YOOtheme Pro is detected through its runtime application class. When present, Calendar Booking reuses YOOtheme's existing UIkit/theme runtime and never enqueues a second UIkit copy. Without YOOtheme, the plugin uses a locally bundled **UIkit 3.25.23** fallback; there is no CDN dependency.

The plugin does **not** scrape arbitrary themes and copy their CSS classes. Themes can integrate through filters, render hooks, wrapper/button/form class filters and CSS variables.

## Privacy and security

Before a stable release, 2.0 must provide:

- signed short-lived slot tokens
- atomic conflict prevention
- UTC/IANA time-zone storage and DST tests
- recurrence-capable busy-time parsing
- busy-only public output
- explicit booking state transitions
- POST-only state changes for cancellation/rescheduling/confirmation
- indexed selector/verifier tokens
- authenticated encryption for calendar credentials
- idempotent queue/notification processing
- WordPress privacy exporter/eraser and retention controls

See [SECURITY.md](SECURITY.md), [docs/SECURITY-PRIVACY.md](docs/SECURITY-PRIVACY.md) and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Development

The GitHub issue tracker is the source of truth for release work. Pull requests should close focused issues and include tests for behavior changes.

Install PHP dependencies before running or testing a source checkout:

```sh
composer install
npm install
npm run build:assets
```

The asset build copies the pinned UIkit fallback from npm into `assets/vendor/uikit/`. Stable release ZIPs will bundle Composer runtime dependencies and built UIkit assets; end users will need neither Composer nor npm.

- Product definition: [docs/PRODUCT.md](docs/PRODUCT.md)
- Architecture: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- Roadmap: [docs/ROADMAP.md](docs/ROADMAP.md)
- Initial issue backlog: [docs/GITHUB-ISSUES.md](docs/GITHUB-ISSUES.md)
- Contributing: [CONTRIBUTING.md](CONTRIBUTING.md)

## License

GPL-2.0-or-later. Copyright © 2026 Cem Firat.
