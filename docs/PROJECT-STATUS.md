# Project status

Status snapshot: 2026-11-01

## Issue #167 — recurring notifications and calendars

The implementation adds explicit single-versus-batch notification scope, one
aggregate email per batch, per-occurrence provider synchronization, series-aware
retry descriptors, local-date summaries, and multi-event ICS attachments with
stable occurrence UIDs. Local recurring-series, paid-series, mail-retry,
durable-effects, and booking-entry smoke tests passed. Commit
`e3eccdcebfd30195d7ecf57cfce8dbb38d876fd6` passed branch readiness CI and all
applicable PR checks in [PR #227](https://github.com/cemfirat/wordpress-calendar-booking/pull/227);
it was merged on 2026-11-01 as `eb53ca584d1292a0239ce93468b45107863f61b6`.
The post-merge tree readback matched the tested branch tree. Release and
public-updater jobs were skipped because no version or release metadata
changed.

The issue remains open: refund/payment amounts are not included in the message,
and DST-boundary plus calendar-client import interoperability acceptance is
still outstanding. No version, release, installation, or product acceptance
change was made.

## Issue #173 — booking-channel behavior

The implementation adds an explicit persisted channel, stable caller-direction
values, conservative legacy migration, administrator review notices, compatible
backup/restore, and server-side computed locations. Renamed/custom type names
and translated caller labels no longer determine phone or in-person behavior.
Focused WordPress smoke tests, offline preflight, 61 Python regressions, and 10
CI-concurrency tests passed locally. Branch readiness and full CI passed on
`4b09d341f46f53d65ed05e560edfc8ebd96ad65c`; independent PR CI passed and
[PR #229](https://github.com/cemfirat/wordpress-calendar-booking/pull/229) was
merged as `8a3b8d3325f39d68203e707f42f7150cb4c2e4c9`. The post-merge tree
(`17b601ab05401702ec8d8e4e3a5fbac728d42c86`) matched the tested branch tree, and
[main CI](https://github.com/cemfirat/wordpress-calendar-booking/actions/runs/41310012941)
passed. Issue #173 is closed. No version, release, installation, or product
acceptance change was made.

## Issue #162 — Google Meet request shape

Current `main` sends an empty JSON object to `spaces.create`, and the contract
test covers success, provider rejection, malformed responses and a missing
meeting URI. [PR #230](https://github.com/cemfirat/wordpress-calendar-booking/pull/230)
added the missing exact-URL assertion; branch readiness, full CI and PR CI passed
and it was merged as `6d97c68b551971a360688501455c7a6344fcb3af` (tree readback
matched `c73b7099cf0cf58943fc49d0998458ea249f751c`). The issue remains open: the
opt-in real-account create/join smoke test has not been run. No version,
release, installation, or product acceptance change was made.

## Issue #219 — Plugin Check findings

Static findings are being removed in reviewed slices, each with full CI, PR CI
and a post-merge tree readback: i18n placeholders and translator comments
([PR #232](https://github.com/cemfirat/wordpress-calendar-booking/pull/232)),
direct-access guards in the YOOtheme module files
([PR #233](https://github.com/cemfirat/wordpress-calendar-booking/pull/233)),
output escaping
([PR #234](https://github.com/cemfirat/wordpress-calendar-booking/pull/234)) and
documented prepared-SQL boundaries
([PR #235](https://github.com/cemfirat/wordpress-calendar-booking/pull/235)).
The local WPCS I18n and EscapeOutput sniffs and the Plugin Check DirectDB sniff
now report no errors. A supplemental, manual-only workflow was restored in
[PR #237](https://github.com/cemfirat/wordpress-calendar-booking/pull/237);
branch CI and independent PR CI passed, and the post-merge tree matched the
tested branch. Main CI passed on `f3da4dfc56a42470bd8ff4b1db25cdb63256f142`
(run 41326525228). The official distribution-aware Plugin Check then passed on
that exact main commit (run
[41326833626](https://github.com/cemfirat/wordpress-calendar-booking/actions/runs/41326833626)).
The workflow now also runs on pull requests and pushes to `main`
([PR #239](https://github.com/cemfirat/wordpress-calendar-booking/pull/239)).
Its PR check passed, as did main CI and the post-merge Plugin Check on
`fa9924d9f1ce8f95165fdd11ddb00f7281a56080` (runs 41328767411 and
[41328763001](https://github.com/cemfirat/wordpress-calendar-booking/actions/runs/41328763001));
the post-merge tree matched the tested branch. The official report contains
784 warnings and no errors: 75 prepared-SQL analysis warnings, 366 direct-query
warnings, 326 no-caching warnings, 11 slow-query warnings, one schema-change
warning and five external-redirect warnings. Counts are finding totals and
categories can overlap. The external redirects are to Stripe checkout after
Stripe-host validation or to fixed Google/Microsoft OAuth endpoints; the
uninstall queries remove plugin-owned data. No findings were suppressed. On 2026-11-01 the Owner explicitly authorized
adding `WordPress Plugin Check` to the existing `main protection` ruleset. The
GitHub readback verified all 12 required contexts, strict main-only
enforcement, no bypass actors, and no changes to the other rules. This status
update's PR is the first PR evaluated under the expanded ruleset. Issue #219
remains open for reviewing warnings; no version, release, installation, or
deployment change was made.
