# 3.19.6 maintenance release: scheduling and installation recovery

This release delivers the reviewed changes from #189, #191 and #192 on top of
3.19.5. It is a focused reliability release, not the full product-qualification
gate tracked in #178.

## Delivered scope

- One effective scheduling-buffer policy expands both candidate and existing
  bookings. Positive booking-type buffers override rule buffers; a type value
  of zero inherits the matching current rule.
- Slot display, final confirmation/approval, capacity checks and rescheduling
  use the same current weekly rule, exception and buffer semantics. Half-open
  boundaries remain explicit, so a slot may begin exactly when cleanup ends.
- Required schema state is verified after dbDelta before the version is marked
  complete. Missing tables, columns or indexes keep booking/write services
  fail-closed and are retried after the hosting/database problem is resolved.
- Schema and post-schema migration attempts use bounded recovery contracts.
  Legacy time conversion cannot double-shift records after an interrupted run.
- First-install defaults are seeded through a serialized, resumable migration.
  Partial seeds retry only missing stable identifiers and completion is recorded
  only after required defaults are observed. Existing configured installations
  are adopted without rewriting administrator customization.

## Upgrade gate

The required fresh-package job upgrades from the actual immutable public
3.19.5 ZIP. Its SHA-256 is pinned to
`4dafa516f11aa03eca7a30b3c21d4a928a6a217b1c1a7ec72ae996a318f4f254`.
The harness refuses non-CI/local-database paths, installs the published baseline
unchanged, creates synthetic historical bookings/payment/configuration with the
old runtime, then installs the exact 3.19.6 candidate.

The test compares every installed baseline/candidate file with its verified ZIP,
preserves the 27 plugin-table snapshots and selected configuration, verifies
encrypted credentials and existing DOI tokens, and confirms the upgraded
migration-readiness/default-seed state. It never accesses the owner's local
WordPress installation or real provider accounts.

## Remaining boundaries

#159 durable lifecycle-effect intent, #155 privacy batch completion, the waiting
list/payment findings, provider recovery issues and the remaining presentation
and product-truthfulness findings stay open. #164 intentionally remains separate
from the general buffer fix because waiting-list promotion holds have their own
cross-type overlap semantics.

A successful package/upgrade run demonstrates the scenarios executed; it is not
a guarantee that all hosting/provider combinations are defect-free.

## Release procedure

Do not merge on a failing gate. After merge, verify the exact public 3.19.6 ZIP,
checksum/provenance and a WordPress update from the previous public release.
Never overwrite 3.19.5.
