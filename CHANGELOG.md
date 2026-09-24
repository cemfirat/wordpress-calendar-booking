# Changelog

## 3.19.0

- Add versioned privacy-safe JSON backup for booking types, resources, assignments, availability, custom fields, safe global settings and non-secret calendar metadata.
- Exclude bookings, booking metadata, customer sessions, tokens, payments/events, waiting lists, delivery/audit logs and reusable credentials from configuration exports.
- Add strict schema validation, no-write dry-run planning, deterministic relationship remapping and transactional restore rollback.
- Restore calendar connections disabled and without importing credentials, requiring administrators to re-enter access material explicitly.

## 3.18.0

- Integrate core booking readiness, scheduler/queue health and mail-transport diagnostics with WordPress Site Health.
- Add actionable links from failed Site Health tests to the relevant WordPress Calendar Booking administration screens.
- Add a bounded debug-information section containing plugin/schema/resource-model versions and non-secret operational state only.
- Add regression coverage proving Site Health output excludes payment/provider secrets and email addresses.

## 3.17.2

- Publish `wordpress-calendar-booking.zip.sha256` alongside the stable plugin ZIP.
- Generate signed GitHub Actions/Sigstore build provenance for the exact ZIP after all release gates pass.
- Verify the packaged checksum before publication and verify both checksum and attestation in the public updater smoke path.
- Document release-integrity verification commands for operators and users.

## 3.17.1

- Monitor all recurring maintenance schedules in scheduler health, including privacy retention and customer-portal cleanup.
- Bound public browser and REST availability requests with pseudonymous request budgets, resource fan-out limits and bounded returned slot counts.
- Retry definite booking and administrator email transport failures through the leased queue with bounded backoff while reconstructing customer data and one-time action links only at execution time.
- Route customer-portal magic links, pending email changes, waiting-list offers and video-meeting-ready notifications through the same durable delivery semantics without copying recipients, raw verifiers or meeting join URLs into queue payloads.
- Suppress stale notifications and uncertain transport outcomes instead of risking duplicate or obsolete customer communication.

## 3.17.0

- Add deterministic partial refunds for paid recurring series using the immutable original payment snapshot.
- Allocate integer minor units across occurrences so all allocations sum exactly to the original charge.
- Support cancelling one occurrence or the selected occurrence plus the remaining series.
- Track cumulative refunded and queued refund amounts atomically and prevent refunds beyond the original payment.
- Serialize provider refund processing per payment and use stable Stripe idempotency keys with explicit partial amounts.
- Accept cumulative Stripe refund totals idempotently and keep privacy-safe per-occurrence audit context.
- Show queued/refunded amounts in wp-admin and show customer cancellation scope plus refund preview before mutation.

## 3.16.0

- Let authenticated booking owners resume pending Stripe Checkout payments from the customer portal.
- Reuse open Checkout Sessions and atomically replace expired session references on the same payment obligation.
- Serialize checkout preparation to prevent concurrent duplicate replacement sessions.
- Reject checkout restart after payment settlement or reservation expiry.
- Preserve the shared payment model for paid recurring series while exposing resume from any owned series occurrence.

## 3.15.0

- Add one upfront payment obligation for bounded recurring booking series, calculated server-side as unit price × occurrence count.
- Store the resulting amount/currency in the payment row as the immutable series price snapshot.
- Resolve every occurrence to the same payment and block confirmation of the complete series until a verified provider event marks it paid.
- Expire all still-reserved occurrences when the series payment expires.
- Allow cancellation/refund of a paid series only for the complete series from its first occurrence; unsupported partial-refund scopes fail closed.
- Show series payment state in the customer portal and write privacy-safe payment lifecycle audit entries to every occurrence.
- Add integration coverage for total amount, shared payment state, confirmation gating, full refund and series-wide expiry.

## 3.14.0

- Add a read-only Stripe Checkout return-status screen for successful and cancelled browser returns.
- Carry only the technical payment UUID in Stripe return URLs; customer PII is never added to return URLs.
- Keep verified Stripe webhooks as the sole authority for payment state and never confirm payments or bookings from GET requests.
- Show pending, paid, failed, expired and refund states using only server-side payment records.
- Add deterministic integration coverage for return URL privacy and non-mutating status lookups.

## 3.13.0

- Add a production Stripe Checkout adapter behind the provider-neutral payment contract.
- Redirect payment-required bookings to Stripe-hosted Checkout without sending customer PII or collecting card data in WordPress.
- Encrypt Stripe API and webhook signing secrets with the shared authenticated secret-storage layer.
- Verify Stripe webhook signatures over the raw request body with timestamp tolerance and map completed, failed and refunded events into the idempotent payment lifecycle.
- Add administrator Stripe configuration, refund controls and deterministic HTTP-fake integration coverage.

## 3.12.0

- Paginate the interactive administrator booking list with bounded 50-row pages.
- Add filtered booking counts plus safe repository limit/offset queries.
- Batch-load sync metadata and privacy-retention flags for the current page instead of issuing per-row queries.
- Preserve date, status and booking-type filters across pagination links.
- Keep CSV exports complete and intentionally unpaginated.
- Add integration coverage with 55 fixture bookings to verify boundaries, totals, filters and batch metadata.

## 3.11.0

- Complete wp-admin CRUD for booking types, custom form fields, availability rules and exceptions.
- Add read-only edit links with prefilled forms and explicit nonce-protected POST delete actions.
- Centralize configuration mutations in a testable administration service.
- Prevent hard deletion of booking types referenced by historical bookings, recurring series or waiting-list entries.
- Remove resource/calendar/video mappings, scoped availability rules and exceptions when an unused booking type is deleted.
- Add integration coverage for editing, deletion cleanup and historical-data guards.

## 3.10.0

- Add a capability- and nonce-protected mail transport diagnostic to Systemstatus.
- Send neutral test messages through the configured WordPress mail path and sender identity.
- Record diagnostic attempts in the existing delivery ledger without persisting recipient addresses or message bodies.
- Capture and redact mail transport errors before storage.
- Show untested, accepted and failed diagnostic state while explicitly distinguishing transport acceptance from inbox delivery.
- Add integration coverage for successful, failed and exceptional mail transports plus secret redaction.

## 3.9.0

- Add a first-run setup readiness dashboard for the core booking path.
- Check active public booking types, assigned resources, effective availability, sender/time-zone settings, scheduler/queue state and a published booking surface.
- Link incomplete checks directly to the relevant WordPress administration screen.
- Keep calendar providers, payments, webhooks and video meetings optional for core readiness.
- Add integration coverage for readiness transitions without loading customer or provider-secret data.

## 3.8.1

- Ship the internationalization hardening merged after the immutable 3.8.0 release was published.
- Make administrator, public booking, customer portal, waiting-list, payment, webhook and video-meeting UI strings translatable with the canonical `wordpress-calendar-booking` text domain.
- Localize frontend JavaScript messages through WordPress.
- Generate the POT catalog reproducibly in CI and gate stable releases on translation coverage.
- Keep machine identifiers, hook names, statuses and audit context keys stable and untranslated.

## 3.8.0

- Preserve durable booking, customer, payment, calendar and configuration data on uninstall by default.
- Add explicit administrator opt-in for destructive uninstall of plugin-owned `wpcb_*` tables and options.
- Always clear plugin cron events and disposable transients during uninstall.
- Make multisite deletion opt-in per site and document uninstall versus privacy erasure/retention.
- Gate stable releases on packaged uninstall-policy checks and dependency vulnerability audits.

## 3.7.0

- Add bounded weekly customer booking series anchored to a canonical signed slot.
- Preserve local wall-clock cadence across timezone offset changes and reject ambiguous/non-existent DST wall times.
- Validate every occurrence before storing the series and create all occurrence reservations in one database transaction.
- Link occurrences through a technical series identifier and stable occurrence index.
- Confirm a reserved series through one Double Opt-In flow and support cancelling or rescheduling the selected occurrence or all remaining occurrences.
- Keep paid booking types out of series creation until payment-series semantics are explicitly defined.
- Add integration coverage for cadence, lifecycle propagation and all-or-nothing conflict handling.

## 3.6.0

- Add a provider-neutral video meeting contract and adapters for Zoom, Google Meet and Microsoft Teams.
- Encrypt provider access tokens using the shared authenticated secret infrastructure.
- Create meetings after booking confirmation, update them on reschedule and remove/cancel them on booking cancellation where supported.
- Execute meeting operations through the leased idempotent retry queue so transient provider failures never roll back booking state.
- Send meeting-ready links idempotently and expose `{meeting_link}` to customer/admin notification templates.
- Keep meeting URLs out of public availability and remove local meeting access links during privacy erasure.
- Add integration coverage with a deterministic fake provider for create/retry/update/delete and secret redaction.

## 3.5.0

- Add opt-in waiting-list entries for full resource/capacity slots.
- Reserve promotion capacity under resource locks so one released seat creates at most one active offer.
- Use encrypted, hashed, expiring one-time offer tokens with scanner-safe GET confirmation and POST-only acceptance.
- Re-promote waiting customers after expired offers and on released booking capacity.
- Add administrator visibility plus WordPress privacy export/erase integration.

## 3.4.0

- Add provider-neutral payment adapters and payment records linked to bookings.
- Add booking-type payment mode, amount and ISO currency configuration.
- Create pending payment obligations alongside paid booking reservations and block confirmation until verified payment.
- Process provider callbacks idempotently without persisting raw callback payloads or card/bank credentials.
- Expire abandoned payments together with their unconfirmed booking reservations.
- Map cancellation of paid bookings to deterministic refund-pending/refunded states.
- Show privacy-safe payment status in wp-admin and the customer portal.
- Add deterministic fake-adapter integration coverage for amount validation, retries, expiry and refunds.

## 3.3.0

- Add a secure customer portal through the `[wpcb_customer_portal]` shortcode without requiring WordPress customer accounts.
- Add scanner-safe one-time magic-link login, encrypted HttpOnly customer sessions, per-session CSRF protection and rate-limited login requests.
- Let customers view only their own bookings and use the canonical lifecycle services to cancel or reschedule active appointments.
- Add contact-data updates with a separately verified one-time flow before an email address is changed.
- Revoke portal sessions during WordPress privacy erasure and automatically clean expired sessions.
- Add integration coverage for token replay protection, session tampering, authorization boundaries, privacy export/erase and cross-customer data isolation.

## 3.2.0

- Add the versioned `wpcb/v1` REST API for public booking types/resources/availability and capability-protected booking administration.
- Add bounded administrator booking pagination and idempotent mutation requests using `Idempotency-Key`.
- Add configurable outbound lifecycle webhooks for booking created, confirmed, rejected, rescheduled and cancelled events.
- Sign webhook requests with HMAC-SHA256, encrypted endpoint secrets, stable event/delivery IDs and schema-versioned privacy-safe payloads.
- Deliver webhooks through the existing leased retry queue and expose redacted delivery history in wp-admin and REST.
- Add integration coverage for REST permissions, idempotency, signing, delivery history and customer-data isolation.

## 3.1.0

- Add explicit resources/staff, booking-type assignments and resource-specific availability/exceptions.
- Route external calendar busy-time blocking and write-back per resource.
- Narrow reservation serialization to resource-specific locks while preserving post-lock availability revalidation.
- Add configurable booking-type/resource capacity and participant counts.
- Keep slots available until seat capacity is exhausted and prevent last-seat overselling under concurrency.
- Optionally show remaining capacity without exposing other participant identities.
- Include participant count in administration, mail-template placeholders and ICS descriptions.
- Add integration and parallel race coverage for resources and group capacity.

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
