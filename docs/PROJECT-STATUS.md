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
merge is pending. Release and public-updater jobs were skipped because no
version or release metadata changed.

The issue remains open: refund/payment amounts are not included in the message,
and DST-boundary plus calendar-client import interoperability acceptance is
still outstanding. No version, release, installation, or product acceptance
change was made.
