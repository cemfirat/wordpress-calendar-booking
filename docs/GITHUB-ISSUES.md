# Initial GitHub Issue Backlog — Historical Archive

> **Historical document.** This file records the backlog used to drive the original 2.x reliability rewrite. The listed work has since been implemented and must not be treated as the current roadmap.

Current planning sources:

- active work: the repository's GitHub Issues and pull requests;
- shipped release sequence: [ROADMAP.md](ROADMAP.md);
- current product capabilities: [PRODUCT.md](PRODUCT.md);
- current technical design: [ARCHITECTURE.md](ARCHITECTURE.md);
- historical release details: [../CHANGELOG.md](../CHANGELOG.md).

## Original 2.x release blockers

The initial rewrite tracked canonical slot validation, atomic reservation, UTC/IANA time handling, recurrence-aware external busy time, busy-only privacy, an explicit booking state machine, scanner-safe public actions, selector/verifier tokens, encrypted provider credentials, leased queues, WordPress privacy tools and the shared UIkit/YOOtheme renderer.

## Original provider follow-up

The next provider phase introduced the calendar connection model, Google Calendar, Microsoft Graph, generic CalDAV/iCloud and provider diagnostics.

## Original operations follow-up

CSV export/filtering, scheduler health, notification delivery history and lifecycle audit history followed the core/provider work.

## Features that were originally marked "later"

The original backlog deferred resources/staff, capacity/group booking, REST/webhooks, customer portal, payments, waiting lists, video meetings and recurring customer bookings. These capabilities are part of the current 3.x codebase and are documented in [PRODUCT.md](PRODUCT.md) and [ARCHITECTURE.md](ARCHITECTURE.md).

Do not add new roadmap items to this archive.
