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
CI-concurrency tests passed locally. Branch readiness and full CI both passed
on `e7dd0bd0bed8530fb257d47677e7ab17a50db126`:
[branch readiness](https://github.com/cemfirat/wordpress-calendar-booking/actions/runs/41309112349),
[full CI](https://github.com/cemfirat/wordpress-calendar-booking/actions/runs/41309126488).
The branch is based on `main` at
`4f5cbe8397f658f7f6312a3b0035a29026159989`. PR review/CI, merge, and a
post-merge tree readback remain outstanding. No version, release, installation,
or product acceptance change was made.
