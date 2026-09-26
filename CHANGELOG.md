# Changelog

## 3.19.10

- Validate configured text, email, textarea, checkbox, select and radio fields through one shared server-side contract before ordinary or waiting-list reservation writes.
- Reject non-scalar shapes, stale choice values, invalid email/checkbox input and configured/storage-backed length violations while preserving legitimate string zero values.
- Support explicit `min_length`, `max_length` and `must_be_checked` rules and reject unknown or ineffective validation configuration before mutation.
- Return recoverable ordinary booking forms on field errors while preserving valid submitted values, the already-qualified anti-bot timestamp and the signed selected slot; final reservation still revalidates the slot.
- Persist validated waiting-list form snapshots for offer recovery, carry them into booking metadata on acceptance and include them in WordPress privacy export/erasure.
- Upgrade the schema to add nullable waiting-list `form_data_json` without rewriting historical rows.
- Verify a real upgrade from the immutable published 3.19.9 package before release.
- Stripe refund-state and late-checkout reconciliation remain separately tracked in #156/#157; full product qualification remains #176/#178.

## 3.19.9

- Fail closed when blocking calendar availability cannot be read completely instead of treating provider failures or malformed responses as free time.
- Reject embedded Google FreeBusy and Microsoft schedule/calendarView errors, invalid intervals and malformed strict ICS/CalDAV availability reads.
- Follow Microsoft Graph calendarView `@odata.nextLink` pages only on the trusted Graph host and exact calendarView path, with strict page, event, byte and time bounds.
- Reject repeated, untrusted, oversized or later-page-failing Microsoft pagination as incomplete availability rather than accepting partial results.
- Keep booking without configured blocking calendar providers independent of provider outages.
- Verify a real upgrade from the immutable published 3.19.8 package before release.
- This maintenance release does not claim live provider-account qualification; that remains tracked in #176/#178.

## 3.19.8

- Persist privacy-minimal booking lifecycle follow-up intent in the leased queue in the same database transaction as reservation, transition and reschedule writes.
- Replay committed lifecycle effects through the existing side-effect hooks so process interruption after an authoritative booking write cannot silently discard required follow-up intent.
- Make paid-cancellation refund allocation idempotent when the same committed lifecycle effect is replayed.
- Fail closed on recurring-series transaction start/commit errors and roll back series/occurrence rows when durable effect storage fails.
- Add real WordPress/MySQL fault injection for transition, single-reservation and recurring-series outbox failures, plus replay coverage.
- Verify a real upgrade from the immutable published 3.19.7 package before release.
- This maintenance release does not claim to solve stale provider work (#158), ambiguous remote creates (#160), refund-provider status semantics (#156) or late Checkout reconciliation (#157).

## 3.19.7

- Complete privacy retention processing across bounded batches instead of repeatedly selecting already-anonymized rows.
- Let personal-data erasure advance past records under an active retention hold and converge across multiple pages.
- Paginate waiting-list privacy export and erasure correctly beyond 50 records without duplicates or premature completion.
- Add real WordPress/MySQL regression fixtures for 49/50/51 and 199/200/201 boundaries and eventual completion.
- Verify a real upgrade from the immutable published 3.19.6 package before release.
- This maintenance release is not full-feature production certification. Remaining audit work is tracked in docs/RELEASE-3.19.7.md and GitHub issue #178.

## 3.19.6

- Use a single effective buffer policy for candidate and existing bookings across slot generation, capacity checks, confirmation/approval and rescheduling; keep half-open interval boundaries explicit.
- Revalidate current weekly availability, exceptions and buffered calendar/internal occupancy while the resource lock is held before capacity-acquiring transitions.
- Verify every required schema table/column/index before recording migration completion, serialize migration attempts and fail closed on incomplete required state.
- Make token/secret/time/status/resource migration markers verifiable and retryable after partial failures, including rollback-safe legacy time conversion.
- Make first-install default settings/types/fields/rules/templates resumable and verified with a bounded seed lock, duplicate-safe retry and adoption of already configured installations.
- Upgrade acceptance now starts from the immutable published 3.19.5 package and must preserve synthetic historical data/configuration before release.


## 3.19.5

- Serialize booking confirmation, approval, expiry and resource moves with shared resource locks; recheck state and reservation deadlines after waiting and roll back failed grouped confirmations.
- Show unavailable required-payment choices before submission while preserving free booking paths. Explain missing local Stripe configuration without claiming a live provider outage or bypassing required payment.
- Give expired single/series reservation links a read-only recovery screen and an explicit fresh-booking route. Never silently revive an expired reservation.
- Ignore obsolete slot responses after booking-type or party-size changes, with independent form state, distinct retry/empty states and coverage with and without AbortController.
- Add an optional private example calendar with exactly ten synthetic entries, safe repeat/removal handling, weekends and multi-day display; no real bookings, payments, mail or external calendars are created.
- Gate installation acceptance on a real upgrade from the checksum-pinned published 3.19.4 archive, preserving historical records, configuration, encrypted credentials and indexed tokens, and comparing installed files to the exact candidate ZIP.
- Scope: this maintenance release delivers the reviewed changes above, not full-feature production certification. Existing buffer/availability, migration recovery, durable side-effect, payment/refund and provider findings remain tracked in issue #178. See docs/RELEASE-3.19.5.md before enabling affected integrations.

## 3.19.4

- Bound public ICS responses to 2 MiB, CalDAV discovery/query responses to 4 MiB and CalDAV mutation responses to 256 KiB.
- Read at most one byte beyond each configured ceiling so oversized responses are rejected before parser work.
- Cap generic CalDAV discovery at 250 calendars and one busy query at 2,000 response records.
- Treat malformed/truncated CalDAV query XML as an explicit provider error rather than an empty busy result.
- Enforce response limits on every manually validated redirect hop without weakening the 3.19.3 SSRF and credential-redirect protections.
- Add deterministic integration coverage for exact limits, body/header overflow, redirect overflow, malformed XML and excessive record counts.

## 3.19.3

- Harden administrator-configurable public ICS and CalDAV targets against server-side request forgery.
- Centralize calendar URL validation, reject embedded credentials and WordPress-unsafe/private network destinations before requests.
- Route configurable calendar traffic through WordPress safe HTTP APIs so redirect destinations are revalidated.
- Fail closed without mutating settings when an unsafe calendar target is submitted.
- Add integration coverage for loopback, RFC1918, link-local, metadata-style and malformed targets.
- Pin the complete Composer/npm dependency graphs with committed lockfiles and locked CI/release installs.
- Gate release packaging on dependency-lock consistency and use the locked Playwright runtime for browser acceptance.

## 3.19.2

- Reject oversized configuration backup JSON before decoding.
- Enforce typed, bounded restore fields instead of relying on downstream sanitization or clamping.
- Validate booking/payment enums, currencies, form-field JSON, availability time ordering, exception date ordering and supported calendar providers.
- Bound section cardinality and template payloads to prevent pathological restore workloads.
- Keep malformed snapshots no-write in dry-run and apply paths while preserving round-trip compatibility with current exports.

## 3.19.1

- Make configuration restore dry-runs distinguish identical matches from real overwrite conflicts and expose bounded field-level conflict details.
- Reject ambiguous local natural-key matches before mutation instead of choosing an arbitrary row.
- Verify repeated application of the same snapshot converges without duplicating availability rules, exceptions, mappings or calendar descriptors.
- Keep conflict previews limited to configuration identifiers and field names; no customer values or reusable secrets are included.

## 3.19.0

- Add versioned JSON backup/restore for booking configuration, resources, mappings, form fields, availability, safe settings and email templates.
- Export reconnect-only calendar metadata while excluding credentials, bookings, customer data, tokens, payments, waiting-list records and operational/audit logs.
- Add strict schema/reference validation and a no-write dry-run plan before restore.
- Apply restore changes transactionally with natural-key ID remapping, preserve existing booking history and roll back partial writes on failure.

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
