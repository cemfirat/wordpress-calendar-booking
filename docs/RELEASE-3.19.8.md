# 3.19.8 maintenance release: durable lifecycle effects

This release publishes the booking lifecycle durability work from #196 and the
recurring-series atomicity follow-up from #197. It is a bounded maintenance
release, not full-feature production qualification.

## Delivered scope

- Reservation creation, booking lifecycle transitions and rescheduling store a
  privacy-minimal `booking_effect` intent in the existing leased
  `wpcb_sync_jobs` queue inside the same database transaction as the
  authoritative booking write.
- The durable payload contains technical booking state only. Customer names,
  e-mail addresses, phone numbers, message bodies, reusable credentials,
  one-time token verifiers and secret action URLs are not copied into it.
- After commit, the existing lifecycle hooks are replayed from the durable
  technical snapshot. Immediate draining is opportunistic; a committed queue row
  remains available to cron/manual recovery if the process exits first.
- Replaying the same paid-cancellation effect cannot allocate the same refund a
  second time: an internal idempotency marker is stored transactionally.
- Recurring-series reservation now fails before writes if its transaction cannot
  start, treats commit failure as an error, and rolls back series/occurrence
  writes when the durable effect insert fails.
- Public error handling does not expose raw storage exception messages.

## Acceptance evidence

The permanent WordPress/MySQL regression installs a trigger that deliberately
rejects only `booking_effect` inserts in the disposable CI database. It proves
that:

1. a booking transition and its audit row roll back together;
2. a normal reservation leaves no booking when the durable intent cannot be stored;
3. a recurring reservation leaves neither its series row nor any occurrence
   bookings when durable intent storage fails;
4. successful lifecycle work leaves a durable queue row without copied customer
   name/e-mail data; and
5. replay of the same paid-cancellation effect leaves exactly one refund
   allocation/idempotency marker.

The existing scheduling, payment, recurring-series, browser, migration,
uninstall, dependency and PHP-version gates remain enabled.

Release acceptance must also install the immutable public 3.19.7 ZIP
(SHA-256 `17e4a249214aab00e399a2746220571a49665f2c11762bedaa09923538e2116a`),
seed synthetic historical state with that old runtime, upgrade to the exact
3.19.8 candidate and verify preservation before publication.

## Deliberate boundaries

This outbox makes **local intent durable**. It does not prove that an external
provider did or did not complete an operation after a lost network response.
Therefore #158 (stale calendar/video work) and #160 (ambiguous remote creates)
remain separate. Stripe provider refund-state handling (#156), late Checkout
reconciliation (#157), provider availability/error semantics and the broader
release qualification in #176/#178 also remain open until their own acceptance
criteria pass.

No existing 3.19.7 release asset is overwritten. A runtime fix is considered
delivered only after the immutable 3.19.8 package, checksum/provenance and public
WordPress updater have all been verified.
