# Refund provider status — issue #156

## Scope and qualification

This continues the existing `fix/refund-provider-status` implementation from
`7ae873a34eec1f8a2600008d4fafa9f0260df7aa`; the missing Main governance changes
through `ebb3f18ae2a805829dc8406a161561e2cda3aee8` are included without changing
the release workflow. [#156](https://github.com/cemfirat/wordpress-calendar-booking/issues/156)
owns this work; [#178](https://github.com/cemfirat/wordpress-calendar-booking/issues/178)
owns the broader release qualification. The separate #158/#160/#162 candidates
and the late-checkout work remain separate.

As of 2026-10-31, this is a prepared correction with local regression coverage.
Full candidate Branch/PR CI, the independent final review and the opt-in Stripe
test-mode acceptance must be recorded in #156 before that issue can be closed.
This document is not evidence of integration, publication, installation or owner
acceptance. Version 3.19.10's published artifacts are not requalified by these
changes.

## Amounts and identity

Each provider submission has a durable UUID, idempotency key, exact minor-unit
amount and currency. A returned provider refund ID stays attached to that
attempt. The payment keeps three distinct amounts:

| Amount | Meaning |
| --- | --- |
| `refund_pending_minor` | Cancellation allocation not currently submitted, including a confirmed failed/canceled attempt's returned allocation. |
| `refund_inflight_minor` | Submitted or ambiguous attempt, including provider `pending` and `requires_action`. |
| `refunded_minor` | Amount whose current provider refund status is `succeeded`. |

The sum cannot exceed the immutable paid amount. Single and remaining-series
allocations use integer minor units; duplicate cancellation effects cannot
allocate the same occurrence twice. A charge-level aggregate cannot establish
the status of a particular refund ID.

Stripe can report a bank return after a refund previously succeeded. A current
`pending`/`requires_action` status moves that attempt's completed amount back to
inflight once; confirmed `failed`/`canceled` moves it back to the queue once.
The completion timestamps are cleared when success is reversed. See the
[Stripe refund lifecycle](https://docs.stripe.com/refunds).

Several earlier refunds can later return at the same time. Status views
prioritize `requires_action`, then `uncertain`, then other active attempts,
before considering a completed attempt. Customer views state when multiple
refunds remain open. The administrator can select each existing active refund
for reconciliation; the service verifies the selected attempt's payment,
provider and active status under the same lock. A stale or foreign selection
fails before provider access and never falls back to a new refund.

## Reconciliation and retries

A signed Stripe refund event identifies the existing attempt. The service
holds the payment's refund lock while the adapter reads that exact refund ID
and applies the current status. A missing/mismatched ID never falls back to a
POST. A replayed POST can return a cached old status, so its recovered ID is
read again before applying the current state. If that GET fails, the recovered
ID remains attached to the uncertain attempt. Provider-read failure leaves the
money and event marker unchanged and returns a retriable webhook error.

Event creation time is retained for audit. It is not the version of a current
refund snapshot: [`Refund.created`](https://docs.stripe.com/api/refunds/object)
is the object's creation time, while
[`Event.created`](https://docs.stripe.com/api/events/object) describes the event.
An older delivered event can therefore trigger a useful current-state GET.

A transport error, malformed successful response or unknown outcome preserves
the attempt and its inflight reservation. A subsequent lookup/configuration
failure cannot prove the earlier POST never executed. Only an explicit
pre-creation rejection on the first submission releases that allocation.

For an uncertain attempt without a provider ID, Stripe POST replay uses the
same stored key and exact amount/currency/metadata, and is allowed only while
the attempt is less than 23 hours old. This leaves a margin before Stripe's
documented [API v1 key retention boundary](https://docs.stripe.com/api/idempotent_requests).
An older, missing-date or future-dated attempt remains blocked for provider
reconciliation; no new attempt or POST is created. A known ID can still be read
at any age. A verified refund event with the stored UUID can reconcile a
previously unknown ID. An operator must not clear the reservation or edit the
UUID to make a blocked retry pass.

## Checks and remaining acceptance

Run the three mandatory commands in [CONTRIBUTING](../CONTRIBUTING.md).
`test_refund_provider.py` additionally executes 34 PHP contracts against the
actual service, repositories, Stripe adapter and signed webhook controller.
Configuration/HTTP and named locks are controlled local doubles. Production SQL
executes in isolated in-memory SQLite with only transaction/`INSERT IGNORE`
spelling translated. These contracts verify state, identity, amount bounds,
retry behavior and rollback; they do not certify MySQL locking or Stripe.

The existing `tests/stripe-payment-smoke.php` now exercises pending, current GET,
duplicates, reordered events, later bank returns, truncated POST responses,
retry prerequisite failure and old-attempt recovery in real WordPress/MySQL
with intercepted HTTP. It runs in the existing WordPress CI job. The release
fixtures verify the new table/zero inflight column while continuing to compare
all 27 historical tables and configuration byte-equivalently. These integration
checks still require execution on this exact candidate.

Opt-in provider acceptance remains open. In an explicitly authorized isolated
Stripe test account and disposable WordPress installation, record the exact
candidate, test-mode payment/refund IDs, verified event IDs and amount snapshots
for single and remaining-series cancellations, delayed success, failure,
cancellation, requires-action, duplicates and reordered delivery. Check a
simulated interrupted refund response and subsequent recovery against the same
provider object. Use Stripe's documented test fixtures; never substitute live
charges or customer data. Do not place secret keys or raw signed payloads in an
issue. Unsupported provider scenarios stay explicitly unverified.

The current publication/installation freeze remains in force. The Main CI
release job uploads an attestation before checking whether the version already
has a release. An existing tag therefore does not make a Main merge free of
publication side effects. Branch/PR checks may proceed after the local gate;
integration requires resolving that concrete release constraint without
weakening the workflow or bypassing the freeze.
