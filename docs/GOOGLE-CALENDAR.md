# Google Calendar provider

WordPress Calendar Booking uses Google's OAuth 2.0 web-server flow. The public plugin does not ship a shared Google client secret; each site administrator supplies their own OAuth client credentials or defines them through `WPCB_GOOGLE_CLIENT_ID` and `WPCB_GOOGLE_CLIENT_SECRET`.

## Google Cloud setup

1. Create or select a Google Cloud project.
2. Enable the Google Calendar API.
3. Configure the OAuth consent screen.
4. Create an OAuth 2.0 **Web application** client.
5. In WordPress open **Calendar & Bookings → Calendar Connections**.
6. Copy the exact redirect URI shown there into the Google OAuth client's authorized redirect URIs.
7. Save the client ID and client secret in WordPress, then connect a calendar.

The redirect URI must match Google's configured URI exactly.

## Least-privilege scopes

Scopes are chosen from the enabled connection capabilities:

- Availability blocking only: `https://www.googleapis.com/auth/calendar.freebusy`
- Confirmed booking write-back only: `https://www.googleapis.com/auth/calendar.events`
- Both: both scopes

The plugin does not request full `calendar` access.

## Token handling

- OAuth `state` is random, short-lived, tied to the current WordPress administrator and single-use.
- Access and refresh tokens are stored in the provider connection's authenticated-encrypted credential payload.
- Expired access tokens are refreshed server-side.
- Missing/revoked refresh authorization produces a reconnect-required error instead of silently falling back to broader access.
- Disconnect performs a best-effort Google token revocation before local credentials are deleted.

## Calendar behavior

Blocking connections use the Calendar FreeBusy endpoint and only consume busy intervals; event titles and attendee details are not required for availability.

Write-back connections use the Calendar Events API. Each booking/connection pair keeps its own remote event identifier, allowing one booking to be written to multiple providers without sharing identifiers.

Provider requests update separate last-read / last-write health timestamps. API errors are reduced to actionable diagnostics; tokens and event/customer payloads are not copied into the sync log.

## Google documentation

- OAuth web-server flow: https://developers.google.com/identity/protocols/oauth2/web-server
- Calendar scopes: https://developers.google.com/workspace/calendar/api/auth
- FreeBusy: https://developers.google.com/workspace/calendar/api/v3/reference/freebusy/query
