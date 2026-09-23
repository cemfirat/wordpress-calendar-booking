# Roadmap

## 2.0 — reliable booking core

Released in stable 2.0.0.

- canonical signed slot tokens and server-side revalidation
- atomic reservation / double-booking protection
- UTC + IANA time-zone model and DST tests
- recurrence-capable ICS/CalDAV busy-time handling
- busy-only privacy defaults
- explicit booking state machine
- safe POST confirmation/cancel/reschedule flows
- scalable selector/verifier tokens
- authenticated provider credential encryption
- leased/idempotent queue and notifications
- WordPress privacy exporter/eraser/retention
- shared UIkit component layer + YOOtheme Pro adapter + fallback

## 2.1 — provider ecosystem

Completed.

- calendar connection data model
- Google Calendar OAuth + FreeBusy + Events
- Microsoft Graph provider
- generic CalDAV provider + iCloud preset
- provider health/diagnostics

## 2.2 — operations/admin

Completed.

- CSV export and filters
- scheduler health
- notification delivery log
- filterable, privacy-conscious booking lifecycle audit history

## 3.x — product expansion

### 3.0
- canonical WordPress Calendar Booking naming
- packaged browser release acceptance

### 3.1
- resources/staff
- capacity/group bookings

### 3.2
- versioned REST API
- signed outbound webhooks

### 3.3
- secure customer portal

### 3.4
- payment lifecycle foundation

### 3.5
- waiting lists and capacity-safe promotion

### 3.6
- provider-neutral video meetings

### 3.7
- bounded recurring customer booking series
- single occurrence or remaining-series management
- local wall-clock recurrence across timezone offset changes

### 3.8
- dependency security audit release gates
- safe uninstall and explicit destructive-delete policy
- complete WordPress internationalization and reproducible translation catalog
- packaged launch hardening for the first production deployment

### 3.9
- first-run setup readiness dashboard
- actionable core configuration checklist
- core-only booking readiness without external provider dependencies
