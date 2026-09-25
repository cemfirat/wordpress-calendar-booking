# 3.19.7 maintenance release: complete bounded privacy-tool progression

This release delivers the privacy batch-correctness work from #194 on top of
3.19.6. It is a focused maintenance release, not the full product-qualification
gate tracked in #178.

## Delivered scope

- Retention processing excludes records that are already anonymized, so bounded
  runs advance through every eligible record instead of repeatedly selecting the
  first page.
- Personal-data erasure advances past bookings under an active retention hold,
  while preserving those records and continuing with later erasable matches.
- Booking erasure uses stable bounded pagination that converges even though
  anonymized rows stop matching the original email filter.
- Waiting-list privacy export and erasure honor WordPress page semantics beyond
  50 records, without duplicates or premature completion.
- Real WordPress/MySQL regression fixtures exercise 49/50/51 and 199/200/201
  boundaries, mixed retained/erasable rows, retries and eventual completion.

These changes are functional privacy-tool corrections. They are not a general
legal-compliance certification.

## Upgrade gate

The required fresh-package job upgrades from the actual immutable public
3.19.6 ZIP. Its SHA-256 is pinned to
`e6ab1b651091b22652cc96245539cb160a5527d606abf58be42c05b2197bc716`.
The harness refuses non-CI/local-database paths, installs the published baseline
unchanged, creates synthetic historical booking/payment/configuration state with
the old runtime, then installs the exact 3.19.7 candidate.

The test compares every installed baseline/candidate file with its verified ZIP,
preserves the 27 plugin-table snapshots and selected configuration, verifies
encrypted credentials and existing DOI tokens, and confirms migration readiness.
It never accesses the owner's local WordPress installation or real provider
accounts.

## Remaining boundaries

#159 durable lifecycle-effect intent, waiting-list/payment findings, provider
recovery issues and remaining presentation/product-truthfulness findings stay
open under #178. The optional PayPal, manual bank-transfer and Open-Banking
backlog (#186-#188) is not part of this maintenance release.

A successful package/upgrade run demonstrates only the scenarios actually
executed; it is not a guarantee that every hosting/provider combination is free
of defects.

## Release procedure

Do not merge on a failing gate. After merge, verify the exact public 3.19.7 ZIP,
checksum/provenance and a WordPress update from the previous public release.
Never overwrite 3.19.6.
