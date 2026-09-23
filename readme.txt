=== WordPress Calendar Booking ===
Contributors: cemfirat
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 3.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Calendar availability and appointment booking with Double Opt-In, optional admin approval, ICS attachments and configurable slots.

== Description ==

WordPress Calendar Booking provides privacy-conscious appointment booking with server-validated availability, Double Opt-In, optional admin approval, calendar blocking/write-back, ICS attachments and configurable slots.

Calendar providers include public ICS feeds, generic CalDAV/iCloud, Google Calendar and Microsoft Graph. Public calendar rendering is busy-only by default. UIkit is used for the frontend with native YOOtheme Pro integration and a local fallback when YOOtheme is unavailable.

Shortcodes:
[wpcb_calendar]
[wpcb_booking_form]
[wpcb_booking_calendar]
[wpcb_customer_portal]

Security and privacy controls include canonical signed slot tokens, atomic reservation, UTC/IANA timezone handling, scanner-safe POST-only booking actions, indexed one-time tokens, authenticated credential encryption, idempotent queue processing, privacy export/erase support and retention controls.

== Changelog ==

= 3.3.0 =
* Add a secure customer portal with one-time magic-link login and encrypted HttpOnly sessions.
* Let customers view, cancel and reschedule only their own bookings.
* Add verified email changes, per-session CSRF protection and privacy-aware session cleanup.

= 3.2.0 =
* Add a versioned REST API for public availability and authenticated administration.
* Add idempotent REST mutations and signed, retryable lifecycle webhooks with encrypted secrets.
* Add privacy-safe webhook delivery history and integration coverage.

= 3.1.0 =
* Add explicit resource and staff scheduling with resource-specific availability and calendar routing.
* Add configurable group capacity, participant counts, remaining-capacity display and concurrency-safe seat reservations.
* Keep capacity 1 equivalent to exclusive single-booking behavior.

= 3.0.0 =
* Normalize the plugin filename, technical prefix, PHP namespace, hooks, shortcodes, block namespace, database/options prefix and text domain.
* Use wordpress-calendar-booking.php as the canonical plugin entry point.
* Remove the previous abbreviated identifier before the plugin's first production deployment.

= 2.0.0 =
* First stable public release.
* Add canonical server-side slot validation and atomic reservation.
* Add UTC/IANA timezone model with DST-safe handling and legacy migration.
* Add recurrence-capable iCalendar parsing with RRULE, EXDATE and overrides.
* Add explicit booking lifecycle, scanner-safe POST-only confirmation/cancellation/rescheduling and indexed one-time tokens.
* Add authenticated provider credential encryption and idempotent sync/notification processing.
* Add privacy exporter/eraser, retention controls and busy-only public calendar output.
* Add Google Calendar, Microsoft Graph, generic CalDAV and iCloud integrations.
* Add shared UIkit frontend rendering with YOOtheme Pro integration and local fallback.
* Add provider health diagnostics and stable GitHub release updates.
