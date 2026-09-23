# WordPress Calendar Booking — Product Definition

## Product goal

WordPress Calendar Booking is a privacy-conscious appointment booking system for WordPress. WordPress owns booking rules, customer state and availability decisions; optional external services contribute busy intervals or lifecycle side effects but are not required for core booking.

The product must remain useful with no Google, Microsoft, Apple, payment, webhook or video-meeting connection.

## Primary user flows

1. An administrator creates booking types, resources/staff, capacity, availability rules and exceptions.
2. Optional calendar connections contribute resource-specific busy intervals and may receive confirmed booking write-back.
3. A visitor receives server-generated slots for a booking type, resource/capacity context and presentation time zone.
4. Submission revalidates the signed slot and reserves capacity inside a resource-scoped critical section.
5. The visitor confirms their email address through Double Opt-In.
6. Depending on configuration, the booking is confirmed automatically or moves to administrator approval.
7. Optional payment obligations, waiting-list promotion and video-meeting creation participate in the lifecycle without bypassing booking-domain validation.
8. Notifications, calendar write-back and signed webhooks run through retryable/idempotent delivery paths.
9. Customers can use the secure portal to inspect and manage their own bookings.
10. Administrators operate bookings, delivery diagnostics, audit history, scheduler health and provider diagnostics from wp-admin.

## Non-negotiable product invariants

- Clients cannot create a booking from an arbitrary timestamp; slots are generated and revalidated by the server.
- Concurrent requests cannot exceed the available capacity of the same resource and time interval.
- Independent resources can accept bookings concurrently.
- UTC is the storage model for instants; IANA time zones define local presentation and recurrence behavior.
- Recurring external calendar events and recurring customer bookings are bounded and timezone-aware.
- Public availability is busy-only by default and never exposes external event details or another customer's identity.
- State-changing public links are inspection-only on GET and require an explicit protected POST to mutate state.
- One-time tokens use selector/verifier storage and cannot be replayed after successful use.
- Provider/payment/webhook/video credentials and secrets use authenticated encryption or provider-hosted credential handling; raw payment-card data is never stored.
- Queue jobs and externally visible side effects are idempotent and retryable.
- Personal data participates in WordPress privacy export/erase and configured retention/anonymization.
- Core booking remains functional without YOOtheme Pro or any external provider.

## Current 3.x capabilities

### Booking and availability

- Booking types with duration, buffers, public/active state and ordering
- Explicit resources/staff with per-resource availability and calendar routing
- Capacity/group booking with party-size accounting
- Weekly availability rules, resource/type scopes and date/time exceptions
- Minimum notice and booking horizon
- Signed canonical slot tokens
- Resource-scoped reservation locking and atomic capacity checks
- Double Opt-In and optional administrator approval
- Cancellation and rescheduling with state-aware validation
- Bounded recurring booking series with single-occurrence or remaining-series management for free series
- Paid recurring series with one upfront server-authoritative series payment and conservative full-series refund semantics
- Explicit lifecycle state machine and privacy-conscious audit history

### Calendar providers

- Public ICS/webcal busy feeds
- Generic CalDAV with discovery, busy reads and ETag-safe write-back
- iCloud preset on the CalDAV provider
- Google Calendar OAuth, FreeBusy and event write-back
- Microsoft Graph OAuth, availability and event write-back
- Resource-specific connection routing
- Redacted provider health/diagnostics

### Customer and integration surface

- Native Gutenberg Booking Form and Availability Calendar blocks
- Shortcodes and YOOtheme Pro Builder integration through the shared renderer
- Secure customer portal with self-service booking actions
- Waiting lists with capacity-safe promotion holds
- Provider-neutral video meetings with Zoom, Google Meet and Microsoft Teams adapters
- Provider-neutral payment lifecycle with Stripe Checkout, verified webhooks and paid-series support
- Versioned `/wp-json/wpcb/v1` REST API
- Signed outbound lifecycle webhooks with encrypted secrets and retryable delivery

### Administration and operations

- Filterable booking administration and complete CSV export
- Bounded/paginated interactive booking list with batched page metadata
- Full CRUD for booking types, form fields, availability rules, exceptions and resources
- Scheduler health and queue controls
- Notification and webhook delivery history
- Mail-transport diagnostic
- First-run readiness dashboard
- WordPress privacy tools and retention controls
- Safe uninstall policy with explicit destructive-delete opt-in
- Reproducible release ZIPs, browser acceptance tests, dependency audits and WordPress/PHP compatibility CI

## Frontend and theme behavior

A shared semantic renderer serves shortcodes, dynamic Gutenberg blocks and YOOtheme Pro elements. YOOtheme installations reuse the existing UIkit runtime; other themes receive the pinned local UIkit fallback only where booking components are rendered.

Themes integrate through documented filters/hooks, wrapper classes and CSS variables rather than copied theme-specific markup.

## Optional integrations

Calendar providers, payment providers, webhooks and video-meeting connections are optional. The first-run readiness check deliberately evaluates only the core path: a public booking type, assigned active resource, effective availability, valid sender/time-zone settings, healthy scheduling and a published booking surface.

## Planning source of truth

This document describes the current product, not an issue backlog. Future work belongs in GitHub issues and [ROADMAP.md](ROADMAP.md). Historical release changes belong in [../CHANGELOG.md](../CHANGELOG.md). Production guidance lives in [OPERATIONS.md](OPERATIONS.md), and security/privacy invariants are maintained in [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md).
