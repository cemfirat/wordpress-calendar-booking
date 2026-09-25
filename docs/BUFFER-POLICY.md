# Effective availability and buffer policy

This document defines the scheduling policy implemented for issues #163 and the
remaining availability revalidation work in #154.

## Authoritative values

Availability is evaluated from the **current saved configuration**. Buffers are
not snapshotted onto each booking.

For each concrete booking slot:

- a positive booking-type `buffer_before_minutes` or
  `buffer_after_minutes` overrides the corresponding availability-rule value;
- a booking-type value of `0` inherits that value from the effective weekly
  rule;
- the same resolution is used for a candidate slot and for every existing
  blocking booking.

Changing a booking type or weekly-rule buffer therefore changes subsequent
availability decisions around existing bookings. This is deliberate and avoids
a schema migration whose only purpose would be to preserve historic buffer
settings.

## Occupancy

Capacity receives raw appointment start/end values. It expands both candidate
and existing booking intervals with their effective buffers and then applies a
half-open overlap test:

`existing_start < candidate_end && existing_end > candidate_start`

An appointment may therefore start exactly when an earlier cleanup buffer ends,
but not one minute before it ends. Party size is counted for every existing
booking whose buffered interval overlaps the candidate. Resource capacities
remain independent.

The database query first selects a bounded safe superset using the largest
currently configured buffers; exact overlap is then checked with each booking's
own effective buffer.

## Lifecycle consistency

Public slot generation, signed-slot reservation revalidation, confirmation,
administrator approval, single rescheduling, recurring reservation/rescheduling
and capacity display all use the same effective buffer policy.

Final confirmation/approval revalidates the current weekly rule, exceptions,
buffers, internal capacity and configured calendar busy intervals while the
resource lock is held. It does not retroactively apply minimum-notice or booking
horizon to a reservation that was valid when acquired.

A reschedule is a new scheduling decision and therefore must still be a
canonical current slot, including rule grid, notice/horizon, exceptions,
capacity and calendar blocking.

## Boundaries

This work does not change waiting-list promotion-hold matching across different
booking types or buffered intervals; that separate defect remains #164. It also
does not change provider-outage semantics (#153), partial migration recovery
(#165), durable side effects (#159), or late-payment reconciliation (#157).
