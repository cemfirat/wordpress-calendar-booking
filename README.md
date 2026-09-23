<p align="center">
  <img src="https://raw.githubusercontent.com/cemfirat/repository-governance/main/assets/brand-banner.webp" alt="Cem Firat creative consultancy artwork" width="900" />
</p>

# WordPress Calendar Booking

Privacy-conscious appointment booking for WordPress with configurable availability, Double Opt-In, optional admin approval, calendar blocking/write-back, ICS attachments, UIkit components and YOOtheme Pro integration.

> **Stable release:** 3.9.0. The public release is built from CI-tested source, includes its runtime dependencies and local UIkit fallback, and supports WordPress 6.5+ with PHP 8.0+.

## Product principles

- Booking rules live in WordPress; external calendars only contribute busy intervals and optional write-back.
- The server generates and validates bookable slots. Browser-provided timestamps are never trusted as availability proof.
- Public calendar output is busy-only: external event details and customer booking details are excluded from the public view model.
- Calendar connections are opt-in and request the minimum useful permissions.
- YOOtheme Pro uses its existing UIkit/theme system. Without YOOtheme, the plugin uses a locally bundled UIkit fallback only where booking components are rendered.
- Core booking works without Google, Microsoft, Apple or any other third-party account.

## Calendar providers

- Public ICS / webcal feed — read-only busy blocking
- Generic CalDAV — standards-based discovery, bounded busy blocking and ETag-safe write-back
- iCloud — Apple-focused preset on top of the generic CalDAV provider
- Google Calendar — OAuth + FreeBusy + Events API
- Microsoft 365 / Outlook — Microsoft Graph OAuth, account-type-aware availability and event write-back

### CalDAV and iCloud

Generic CalDAV connections use `current-user-principal`, `calendar-home-set` and calendar collection discovery where the server supports them. Busy reads use a bounded RFC 4791 `calendar-query`; event updates/deletes use ETags with `If-Match` so remote changes are not overwritten silently.

The iCloud preset uses `https://caldav.icloud.com/` and the same generic provider. For direct username/password-style CalDAV access, use an Apple Account email plus an app-specific password. Apple requires two-factor authentication for app-specific passwords. Apple also supports account authorization for compatible third-party apps; that can replace app-specific passwords when an app implements Apple's authorization flow.

### Provider diagnostics

A unified **Calendar Diagnostics** screen shows provider capabilities, selected calendar, credential/reconnect state, last successful availability read, last successful write, and a redacted error summary with timestamp. Manual read/write tests require an administrator capability and WordPress nonce. Write diagnostics create one neutral five-minute event about 180 days in the future and remove it immediately; credential/token values are never rendered.

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
- Shortcodes (`[wpcb_booking_form]`, `[wpcb_calendar]`, `[wpcb_booking_calendar]`, `[wpcb_customer_portal]`)
- Native dynamic Gutenberg blocks for the Booking Form and Availability Calendar
- UIkit fallback assets when no compatible UIkit/YOOtheme runtime is present

YOOtheme Pro is detected through its runtime application class. When present, Calendar Booking reuses YOOtheme's existing UIkit/theme runtime and never enqueues a second UIkit copy. Without YOOtheme, the plugin uses a locally bundled **UIkit 3.25.23** fallback; there is no CDN dependency.

The Gutenberg editor uses read-only placeholders; live availability and booking actions are rendered only on the frontend through the same shared component layer.

The plugin does **not** scrape arbitrary themes and copy their CSS classes. Themes can integrate through filters, render hooks, wrapper/button/form class filters and CSS variables.

## Privacy and security

The current release provides:

- signed short-lived canonical slot tokens
- atomic conflict and capacity prevention
- resource/staff scheduling with resource-specific availability and calendar routing
- configurable group capacity and participant counts
- UTC/IANA time-zone storage and DST tests
- recurrence-capable busy-time parsing
- busy-only public output
- explicit booking state transitions
- scanner-safe GET pages with POST-only cancellation/rescheduling/confirmation mutations
- indexed selector/verifier one-time tokens
- authenticated encryption for calendar credentials
- idempotent queue/notification processing
- WordPress privacy exporter/eraser and retention controls

See [SECURITY.md](SECURITY.md), [docs/SECURITY-PRIVACY.md](docs/SECURITY-PRIVACY.md) and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Development

The GitHub issue tracker is the source of truth for bugs, features and release work. Pull requests should close focused issues and include tests for behavior changes.

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
- First-run setup: [docs/FIRST-RUN.md](docs/FIRST-RUN.md)
- Initial issue backlog: [docs/GITHUB-ISSUES.md](docs/GITHUB-ISSUES.md)
- Contributing: [CONTRIBUTING.md](CONTRIBUTING.md)

## License

GPL-2.0-or-later. Copyright © 2026 Cem Firat.


## Installation

Download `wordpress-calendar-booking.zip` from the latest GitHub release and upload it through **Plugins → Add New → Upload Plugin**. Stable GitHub releases are then discovered through WordPress's native plugin update flow.

Source checkouts require Composer/npm only for development. Release ZIPs already include production Composer dependencies and the built local UIkit fallback.


## REST API and webhooks

Version 3.2 exposes a versioned `/wp-json/wpcb/v1` API. Public endpoints expose only public booking types, explicitly public resource labels and privacy-safe availability. Booking administration and webhook configuration require WordPress administrator capabilities.

State-changing REST requests require an `Idempotency-Key` header. Outbound lifecycle webhooks use encrypted endpoint secrets and an `X-WPCB-Signature: sha256=...` HMAC over `<timestamp>.<raw-body>`. Webhook payloads are schema-versioned and deliberately omit customer name, email, phone, notes and provider credentials.


## Customer portal

Add `[wpcb_customer_portal]` to a normal WordPress page to provide self-service access without creating WordPress customer accounts. Customers request a one-time magic link by email; the link opens a scanner-safe confirmation screen and creates an encrypted, HttpOnly portal session only after an explicit POST confirmation.

Authenticated customers can see only bookings matching their verified session email, open booking details, cancel or reschedule active appointments through the same server-side lifecycle services used elsewhere, and update contact details. Email-address changes require a second one-time verification link before the booking email is changed. Portal mutations use per-session CSRF tokens, login requests are rate-limited, and WordPress privacy erasure revokes matching portal sessions.


## Payments

Version 3.4 adds a provider-neutral payment lifecycle foundation. Booking types can be configured as free or payment-required with a price and ISO currency. Paid reservations receive a separate pending payment record; booking confirmation remains blocked until the payment is verified.

Payment adapters receive only a technical payment identifier, amount, currency and expiry. Raw card numbers, CVC/CVV values, bank credentials and full provider callback payloads are never stored by the plugin. Provider callbacks are idempotent, expired pending payments release unconfirmed reservations, and cancelling a paid booking moves its payment into an explicit refund workflow.


## Video meetings

Version 3.6 adds provider-neutral video meeting orchestration for Zoom, Google Meet and Microsoft Teams. Meeting credentials are encrypted at rest, meeting creation/update/cancellation runs through the idempotent retry queue, and join links are included only in customer/admin communication after a booking is confirmed. Public availability never contains meeting URLs.


## Recurring bookings

Version 3.7 adds bounded weekly booking series for free booking types. The first signed canonical slot anchors the series; every later occurrence is regenerated and revalidated on the server before any booking is stored. Series preserve the configured local wall-clock time across UTC offset changes, reject ambiguous/non-existent DST wall times, and can be cancelled or rescheduled for one occurrence or the selected occurrence plus all remaining appointments.

Series creation is all-or-nothing. If any occurrence is no longer bookable, no partial series is stored. Paid booking types are intentionally excluded until payment authorization, expiry and refund behavior for a whole series is specified explicitly.
