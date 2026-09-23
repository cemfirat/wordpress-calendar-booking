# Changelog

## 3.0.0

- Normalize all historical abbreviated identifiers to the WordPress Calendar Booking naming scheme before first production deployment.
- Rename the canonical plugin entry point to `wordpress-calendar-booking.php`.
- Use `Wpcb` / `wpcb_` for internal PHP and WordPress identifiers and `wordpress-calendar-booking` as the text domain.
- Rename shortcodes, hooks, options, database tables, block namespace, CSS/JS identifiers and test fixtures consistently.
- No legacy alias or data migration layer is shipped because the plugin has not been deployed.

## 2.0.0

- First stable public release of the rewritten WordPress Calendar Booking plugin.
- Validate canonical server-generated slots and serialize reservations to prevent forged/off-grid and double bookings.
- Store booking-domain instants in UTC with an explicit IANA booking timezone, DST boundary handling and legacy 1.x time migration.
- Expand recurring iCalendar events with RRULE, RDATE, EXDATE, RECURRENCE-ID overrides and VTIMEZONE support.
- Keep public calendar output busy-only and exclude customer/event details from public view models.
- Enforce an explicit booking lifecycle with scanner-safe GET pages and nonce-protected POST-only confirmation, cancellation and rescheduling.
- Use indexed selector/verifier one-time tokens and authenticated encryption for provider credentials.
- Add leased/idempotent queue processing, notification idempotency and privacy exporter/eraser/retention support.
- Add Google Calendar, Microsoft Graph, generic CalDAV and iCloud providers with provider-neutral connection routing.
- Add safe provider health diagnostics with protected manual read/write tests.
- Add a shared UIkit component layer, native YOOtheme Pro integration and a pinned local UIkit fallback.
- Add PHP 8.0–8.5 and WordPress 6.5/current integration coverage.
- Add stable GitHub release updates and reproducible distribution packaging.
