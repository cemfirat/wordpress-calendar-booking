# Contributing

Thanks for helping improve WordPress Calendar Booking.

## Workflow

1. Check the issue tracker before starting work.
2. Keep pull requests focused on one issue or a tightly related group.
3. Add regression tests for behavior changes.
4. Do not commit secrets, calendar credentials, OAuth tokens or real customer data.
5. Security-sensitive findings should follow SECURITY.md.

## Compatibility

The 2.0 baseline targets WordPress 6.5+ and PHP 8.0+ unless an issue explicitly changes that policy.

Frontend work should reuse the shared semantic component layer. YOOtheme and fallback UIkit adapters must not fork booking/domain logic.

## Checks before pushing

Run from a checkout with Python 3.10+, PHP, Node and Bash available:

```sh
python3 -B scripts/preflight.py
node --test .github/tests/ci-concurrency.test.mjs
python3 -B -m unittest discover -s .github/tests -p 'test_*.py' -v
```

These offline checks validate project PHP, JavaScript, Python, shell and JSON
syntax plus CI/build-tool contracts. Missing interpreters and malformed files
fail the checks rather than being silently skipped. Dependencies and generated
assets are excluded. They do not replace the full WordPress, database, browser,
dependency-audit and public-updater jobs in GitHub Actions.

The package job builds twice and compares the ZIP bytes. Published releases
are not overwritten by later CI-only commits. The public updater job separately
verifies the downloaded release ZIP and its main-workflow attestation, then
checks every installed plugin file against that verified public archive.
