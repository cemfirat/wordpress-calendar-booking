# Security Policy

## Supported versions

Security fixes are provided for the current stable 3.x release line. Production sites should run the latest published stable release because security fixes may be shipped only in the newest 3.x version.

| Version | Security support |
| --- | --- |
| Latest stable 3.x | Supported |
| Older 3.x releases | Upgrade to the latest stable release |
| 2.x and imported / prototype 1.x | Not supported |
| Development branches | Not supported for production use |

Development branches may contain unfinished migrations or behavior and should not be deployed to production.

## Dependency security

Every pull request and stable release is gated on vulnerability advisory checks for PHP runtime dependencies and production npm dependencies. High or critical npm advisories and Composer security advisories must be resolved before a stable release can be published.

## Reporting a vulnerability

Please do not publish exploit details in a public issue while an affected public version remains unfixed.

Use the repository's private security reporting feature when available, or contact the maintainer through https://cemfirat.com/.

Include the affected version, impact, and enough reproduction detail to validate the issue. Avoid including real customer data, access tokens, calendar credentials, payment credentials, or other secrets.

Public issues may describe the affected invariant and acceptance criteria without weaponized reproduction steps.

## Disclosure

Security reports are validated privately. A fix should be prepared and released before detailed public disclosure when a published version is affected. Release notes may describe the impact and upgrade requirement without exposing secrets or unnecessary exploit detail.
