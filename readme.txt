=== WordPress Calendar Booking ===
Contributors: cemfirat
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Calendar availability and appointment booking with Double Opt-In, optional admin approval, ICS attachments and configurable slots.

== Description ==

WordPress Calendar Booking provides configurable availability, server-validated appointment slots, Double Opt-In, optional administrator approval, ICS attachments and privacy-conscious calendar blocking.

Core features include:
* Signed short-lived slot tokens with server-side availability revalidation
* Atomic reservation protection against overlapping concurrent bookings
* UTC storage with an explicit IANA booking timezone and DST-safe slot generation
* Recurring ICS busy-time handling with RRULE, EXDATE and overrides
* Busy-only public calendar output
* Explicit booking lifecycle transitions
* POST-only confirmation, cancellation and rescheduling actions
* Indexed one-time action tokens
* Authenticated provider-secret storage
* Retryable/idempotent calendar and notification queues
* WordPress personal-data exporter/eraser and optional retention anonymization
* Shared UIkit frontend components with native YOOtheme Pro Builder elements
* Locally bundled UIkit fallback when YOOtheme Pro is not active

Shortcodes:
[cemb_calendar]
[cemb_booking_form]
[cemb_booking_calendar]

Stable releases are distributed from the public GitHub repository.
