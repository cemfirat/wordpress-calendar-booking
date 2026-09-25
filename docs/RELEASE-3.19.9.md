# 3.19.9 maintenance release: complete fail-closed provider availability

This release packages the already integrated work from PRs #199 and #200. It is a focused maintenance release, not full provider or full-product qualification.

## Delivered scope

- Blocking calendar-provider reads now preserve **unknown/incomplete availability** as an error. Public availability and final slot validation fail closed instead of silently treating provider failures as free time.
- Google FreeBusy rejects calendar-level HTTP-200 error payloads and invalid busy intervals.
- Microsoft getSchedule and calendarView reject embedded or incomplete availability payloads.
- Public ICS and CalDAV availability use strict parsing for booking decisions; malformed fresh data is not accepted as an empty calendar.
- Microsoft calendarView follows provider-supplied `@odata.nextLink` pages only when the URL remains HTTPS on `graph.microsoft.com` and exactly matches the original calendarView path.
- Microsoft pagination is bounded to 10 pages, 5,000 events, 2 MiB per page, 8 MiB total and 30 seconds. Repeated links, untrusted links, limit exhaustion and later-page failures are incomplete availability, not success.
- Sites without a configured blocking provider calendar keep their core booking path independent of provider outages.

## Upgrade gate

The required fresh-install release gate must also install the immutable public 3.19.8 package, pinned to SHA-256:

`0e48985415b613cab7db542aea3048506ec4735a3cd450e8971b0492b1960ad6`

It then upgrades that isolated synthetic installation to the exact 3.19.9 candidate and verifies the existing upgrade invariants. The 3.19.8 release asset must not be modified or replaced.

## Verification boundaries

The deterministic HTTP/WordPress tests prove response/error/pagination handling for controlled provider responses. They do **not** constitute live-account certification across Google, Microsoft, CalDAV/iCloud hosts or every hosting network. Real provider-account qualification remains tracked by #176.

This release also does not close unrelated provider reconciliation or payment findings such as stale queued provider work (#158), ambiguous remote creates (#160), refund status semantics (#156), late payment reconciliation (#157), or the full release gate (#178).

## Release checks

Before merge:

1. All CI checks must pass on the exact PR head.
2. Both WordPress matrices must pass the provider fail-closed and Microsoft paging smoke coverage.
3. Fresh installation, packaged browser acceptance, imported migration and uninstall policy must remain green.
4. The real checksum-pinned 3.19.8-to-candidate upgrade must pass.

After merge:

1. Verify the immutable public 3.19.9 ZIP and checksum/provenance.
2. Verify WordPress discovers and installs 3.19.9 through the public GitHub updater.
3. Close #153 and #161 only after the versioned release has actually been published; retain live-account qualification in #176.
