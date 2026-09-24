# Booking transition serialization

This document describes the first implementation slice of issue #154, not the
completion of the full release-readiness tracker #178.

## Lock and commit boundary

Reservation, confirmation, approval and due-expiry decisions share the existing
per-resource MySQL advisory lock. A transition discovers resource IDs, acquires
them in numeric order, and then reads the booking again. If a booking moved to a
resource outside the acquired set while the operation waited, the operation
fails with a retryable conflict instead of acquiring another lock out of order.
Single and recurring rescheduling acquire every source and destination resource.
Independent resources do not share a lock.

A recurring confirmation group is bounded to 24 bookings. Its transaction begins
only after all resource locks have been acquired; its reads therefore do not use
a pre-lock InnoDB snapshot. If a member fails validation or its state write fails,
all preceding group writes are rolled back. Notifications run after commit and
release, not while the transaction holds the resource locks. This is NOT a durable
outbox: the commit-to-effect crash window remains tracked separately in #159.

The existing one-time token lock may enclose a resource operation. Resource code
does not acquire that token lock in reverse order. Payment eligibility is read
inside the resource critical section; downstream payment/refund callbacks run
after its release. Provider payment reconciliation is still separate work (#157).
MySQL named locks are connection-scoped, not released by transaction commit, and
allow recursive acquisition. ResourceLock now balances each acquisition count.

## Expiration policy

A valid confirmation token does not extend a reservation. An unconfirmed booking
with a non-null deadline at or before the current time cannot be confirmed; the
caller receives `wpcb_reservation_expired` without consuming the token. The
customer must select a new slot. Explicit null legacy deadlines retain their
existing unbounded-hold meaning when a resource is assigned.

A scheduled expiry rechecks the deadline under the resource lock. A renewed hold
is not expired using an earlier scan result. Explicit compensation operations,
such as failure to create a payment obligation, may still expire a hold early.
Resource-less legacy records may be expired/cancelled (releasing capacity), but
cannot gain capacity through confirmation by guessing a resource assignment.

## Verification and remaining scope

- `.github/tests/booking-transition-contract.php` uses actual lifecycle, state
  machine and ResourceLock classes with explicit storage/availability doubles.
- `tests/transition-capacity-smoke.php` is required by the existing capacity
  integration test on both WordPress/PHP environments. Separate WP-CLI processes
  exercise actual MySQL locks, fresh reads, opposite-direction rescheduling,
  confirmation versus reservation/expiry, token consumption, and group rollback.
- Existing packaged browser and payment/series integration tests remain enabled.

The current SlotService availability/buffer policy is deliberately unchanged in
this slice. Consistent effective-rule/exception and buffer revalidation (#163 and
the remaining acceptance of #154) still needs implementation and integration
proof. Current provider failure semantics (#153), the durable outbox (#159), and
late-payment handling (#157) are not claimed fixed here. No new release version is
published by this change.

Primary database contracts:
- https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html
- https://dev.mysql.com/doc/refman/8.0/en/innodb-transaction-isolation-levels.html
