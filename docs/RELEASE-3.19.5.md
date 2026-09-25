# 3.19.5 maintenance release: scope and upgrade acceptance

This release delivers the focused changes in PRs #179, #182, #183 and #184.
It is **not** the final full-feature production acceptance tracked in #178.
No functionality is removed to make the audit disappear. Open findings retain
their original acceptance criteria.

## Delivered scope

- Resource-scoped serialization and state/deadline rechecks for booking
  transitions, grouped-confirmation rollback and source/destination move locks.
- Public entry checks for locally unavailable required payments, free-type
  availability, clear configuration guidance and no payment bypass.
- Read-only expired-reservation recovery for single bookings and series.
- Per-form protection against obsolete type/party-size responses, including
  browsers without AbortController. Empty availability and request errors are
  different states.
- An optional private ten-entry example calendar. In the WordPress admin menu,
  open **Kalender & Buchungen > Beispielkalender**, explicitly confirm creation,
  then choose **10 Beispieleinträge erzeugen**. The separate removal action deletes
  only the marked example set. See [demo calendar](DEMO-CALENDAR.md).

## Upgrade gate

The existing required fresh-release job retains its fresh-install checks and
also invokes `tests/upgrade-from-stable.py`. This test:

1. Downloads the actual public `v3.19.4/wordpress-calendar-booking.zip`, verifies
   its pinned SHA-256 `bbbe51c7a7e5ef8c481b253910adbb70bcb85ee41d7b9e23fe244450f0ff3bf2`
   and checks every installed baseline file against it. It never synthesizes
   the old release by changing a version header.
2. Uses a separate temporary WordPress directory and uniquely named local CI
   database. It refuses non-CI paths and a nonlocal database connection.
3. Creates synthetic confirmed, expired-hold and cancelled booking records,
   metadata, historical payment data, an indexed DOI token and disabled
   encrypted provider credentials using the old plugin.
4. Installs the exact new candidate ZIP, starts a new PHP process, and compares
   all 27 plugin tables and selected saved configuration with the pre-upgrade
   snapshot. It verifies credential decryption, old token validity, expired-hold
   rejection and optional demo creation/removal without changing those records.
5. Compares every installed candidate file against the candidate archive and
   removes only its own disposable database.

This is executable upgrade acceptance with synthetic data, not access to or a
backup of any customer's live installation. WordPress/MySQL/browser jobs run in
GitHub Actions. Local syntax and harness tests do not replace these jobs.
The public updater job then independently verifies the published ZIP, checksum,
build provenance and installation via the normal WordPress update path.

## Remaining boundaries

Existing findings in #154/#163 (effective availability and buffers), #165
(partial migration recovery), #159 (durable side-effect intent), #155
(large privacy batches), the payment/refund issues and provider-reconciliation
issues are **not** closed by this maintenance release. The demo's private
calendar renderer does not fix or certify the public calendar's #169 behavior.
Use the per-feature acceptance status in #178 before production deployment,
particularly for paid bookings and connected calendars/video providers.

A green CI result demonstrates the scenarios executed, not absence of all bugs.
Real mail arrival, live provider-account behavior and the owner's local
WordPress configuration remain separate verification boundaries.

For the reported required-payment message, choose a genuinely free booking type
for a free booking, or configure the required Stripe credentials and webhook.
The update improves validation and recovery; it cannot configure external
accounts or convert a paid booking into a free one without an explicit choice.

## Release procedure

Review the final PR head; retain all CI gates, including this upgrade test.
Publish under the new version only after they pass. Never overwrite 3.19.4.
Verify the post-merge run, exact public asset and public updater before marking
this version delivered. Reopen or retain any issue whose acceptance is incomplete.
