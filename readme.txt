=== WordPress Calendar Booking ===
Contributors: cemfirat
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 3.20.0
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
[wpcb_waiting_list]

Security and privacy controls include canonical signed slot tokens, atomic reservation, UTC/IANA timezone handling, scanner-safe POST-only booking actions, indexed one-time tokens, authenticated credential encryption, idempotent queue processing, privacy export/erase support and retention controls.

== Changelog ==

= 3.20.0 =
* Make configuration restore conflict-aware before overwriting local natural-key matches.
* Reject ambiguous duplicate local configuration before writes.
* Keep conflict previews bounded and free of credentials/customer data.
* Prove repeated restores converge without duplicating rules, exceptions or mappings.

= 3.19.0 =
* Add privacy-safe versioned JSON configuration backup and validated restore.
* Add no-write restore preview, deterministic relationship remapping and transactional rollback on import failure.
* Exclude customer records, tokens, payments, logs and reusable credentials; restored calendar metadata remains disabled until reconnect.

= 3.18.0 =
* Integrate booking readiness, scheduler health and mail-transport diagnostics with WordPress Site Health.
* Add a privacy-safe Site Health debug-information section with bounded operational metadata only.
* Keep customer data, provider/payment credentials, tokens and raw errors out of Site Health output.

= 3.17.2 =
* Publish a portable SHA-256 checksum alongside the stable release ZIP.
* Add GitHub Actions/Sigstore build provenance attestation for the exact release ZIP.
* Verify the checksum and signed provenance in the post-release updater smoke test.

= 3.17.1 =
* Monitor all recurring maintenance schedules in Systemstatus.
* Bound public availability request rate, resource fan-out and returned result cost.
* Retry definite booking and administrator email transport failures through the leased queue without persisting customer data or one-time action verifiers.
* Apply the same durable, stale-safe delivery semantics to customer-portal, waiting-list and video-meeting-ready email notifications.

= 3.17.0 =
* Add deterministic partial refunds for paid recurring series.
* Support single-occurrence and remaining-series cancellation with exact minor-unit allocation from the immutable original payment.
* Track cumulative refunded and queued amounts, serialize refund processing and prevent over-refunds.
* Send explicit partial refund amounts to Stripe with stable idempotency keys and show refund scope/amount in customer and administrator UI.

= 3.16.0 =
* Let authenticated customers safely resume pending Stripe Checkout payments from the customer portal.
* Reuse an open Stripe Checkout Session and replace an expired session on the same payment obligation.
* Serialize checkout preparation and reject resume after settlement or local reservation expiry.

= 3.15.0 =
* Add one upfront server-calculated payment obligation for bounded recurring booking series.
* Gate confirmation of every paid-series occurrence on the same verified payment state.
* Expire all reserved occurrences together when the series payment expires.
* Allow full-series cancellation/refund only from the first occurrence; unsupported partial refund scopes fail closed.
* Show the shared series payment consistently in customer self-service and audit every occurrence without copying customer data.

= 3.14.0 =
* Add read-only Stripe Checkout success/cancel return status pages.
* Keep Stripe webhooks as the only payment-state authority; return GET requests never confirm payments or bookings.
* Carry only technical payment UUIDs in return URLs and keep customer PII out of them.

= 3.13.0 =
* Add Stripe-hosted Checkout for payment-required booking types.
* Verify signed Stripe webhooks and process payment/refund events idempotently.
* Encrypt Stripe API and webhook secrets; WordPress never collects or stores raw card data.

= 3.12.0 =
* Paginate the interactive administrator booking list in bounded 50-row pages.
* Batch-load sync and privacy-retention metadata for each page instead of querying per booking row.
* Preserve booking filters across pagination while keeping CSV export complete and unpaginated.

= 3.11.0 =
* Add edit and delete actions for booking types, custom form fields, availability rules and exceptions.
* Prefill existing configuration in the admin forms and keep all mutations nonce-protected POST actions.
* Protect booking types with historical bookings, recurring series or waiting-list entries from hard deletion.
* Clean mappings, rules and exceptions when an unused booking type is deleted.

= 3.10.0 =
* Add a protected mail-transport diagnostic and safe administrator test email.
* Record test attempts in the privacy-conscious delivery ledger without storing recipient addresses or message bodies.
* Show accepted/failed/untested mail state in Systemstatus and document the difference between transport acceptance and inbox delivery.

= 3.9.0 =
* Add a first-run readiness checklist to the WordPress administration dashboard.
* Check core booking type, resource assignment, availability, sender/time-zone settings, scheduler health and a published booking surface.
* Keep external providers optional so a core-only installation can become booking-ready.

= 3.8.1 =
* Publish the completed internationalization hardening from the post-3.8.0 source state.
* Add reproducible POT generation and translation regression checks to CI.
* Internationalize admin, public booking, customer portal and provider-facing UI with the canonical text domain.

= 3.8.0 =
* Add explicit safe uninstall behavior: durable data is preserved by default.
* Add administrator opt-in for complete plugin-owned data deletion during uninstall.
* Gate releases on packaged uninstall-policy tests and dependency security audits.

= 3.7.0 =
* Add bounded weekly recurring customer booking series.
* Preserve local wall-clock cadence across DST offset changes with server-side occurrence validation.
* Add single-occurrence or remaining-series cancellation/rescheduling and all-or-nothing reservation tests.

= 3.6.0 =
* Add provider-neutral Zoom, Google Meet and Microsoft Teams video meeting integrations.
* Encrypt meeting credentials and run create/update/delete operations through the idempotent retry queue.
* Deliver join links only after confirmation and keep them out of public availability output.

= 3.5.0 =
* Add privacy-aware waiting lists with capacity-safe promotion holds.
* Add scanner-safe one-time promotion confirmation and automatic re-promotion after expiry.
* Add administrator history, privacy export/erase support and integration coverage.

= 3.4.0 =
* Add provider-neutral payment lifecycle records and adapter contract.
* Add paid booking-type configuration, pending-payment reservation expiry, idempotent provider events and deterministic refunds.
* Never store raw card or bank credentials in WordPress.

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
