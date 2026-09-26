"""Real 3.19.9-to-candidate acceptance, confined to disposable GitHub CI storage.

The published baseline is downloaded, not synthesized by changing a version header.
The original fresh-install fixture is never downgraded or reused as the upgrade DB.
"""
from __future__ import annotations

import hashlib
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import urllib.request
import zipfile

BASELINE_VERSION = '3.19.9'
BASELINE_SHA256 = 'aa25b2b4e690514e86ed88a92fc5fe77fcc0e0703ebb87d7776f7a5cb69b9df9'
BASELINE_URL = ('https://github.com/cemfirat/wordpress-calendar-booking/releases/'
                'download/v3.19.9/wordpress-calendar-booking.zip')
PLUGIN = 'wordpress-calendar-booking'
MAX_ARCHIVE = 10 * 1024 * 1024


def archive_files(archive: Path) -> dict[str, bytes]:
    """Bound and validate paths before inspecting/installing a CI archive."""
    files: dict[str, bytes] = {}
    total = 0
    with zipfile.ZipFile(archive) as package:
        for item in package.infolist():
            parts = PurePosixPath(item.filename).parts
            if (not parts or parts[0] != PLUGIN or item.filename.startswith('/')
                    or '\\' in item.filename or '..' in parts or ':' in item.filename
                    or item.filename.rstrip('/') != '/'.join(parts)
                    or stat.S_ISLNK(item.external_attr >> 16)):
                raise ValueError('Unsafe plugin archive entry')
            total += item.file_size
            if total > 80 * 1024 * 1024:
                raise ValueError('Expanded plugin archive exceeds the test bound')
            if item.is_dir():
                continue
            name = '/'.join(parts[1:])
            if not name or name in files:
                raise ValueError('Duplicate or invalid plugin archive entry')
            files[name] = package.read(item)
    if 'wordpress-calendar-booking.php' not in files:
        raise ValueError('Missing plugin entry point')
    return files


def verify_installation(archive: Path, installed: Path) -> int:
    expected = archive_files(archive)
    actual: dict[str, bytes] = {}
    for entry in installed.rglob('*'):
        if entry.is_symlink():
            raise ValueError('Unexpected installed symlink')
        if entry.is_file():
            actual[entry.relative_to(installed).as_posix()] = entry.read_bytes()
    if expected != actual:
        raise ValueError('Installed files do not exactly match the verified candidate')
    return len(actual)


def ci_paths(source: Path, workspace: Path, temporary: Path) -> None:
    if os.environ.get('GITHUB_ACTIONS') != 'true':
        raise RuntimeError('This database-writing acceptance test runs only in disposable GitHub CI')
    source, workspace, temporary = source.resolve(), workspace.resolve(), temporary.resolve()
    if (temporary == Path('/') or temporary not in source.parents or source == temporary
            or not (source / 'wp-config.php').is_file()
            or not (workspace / 'dist' / f'{PLUGIN}.zip').is_file()):
        raise RuntimeError('Invalid isolated CI paths')


def wp(root: Path, *args: str, extra: dict[str, str] | None = None) -> str:
    result = subprocess.run(['wp', *args, '--path=' + str(root), '--no-color'],
                            env={**os.environ, **(extra or {})}, text=True,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
    if result.returncode:
        # Arguments can contain the CI admin password. Never print the command.
        message = result.stderr[-2500:].replace('integration-only', '[redacted]')
        raise RuntimeError(f'WP-CLI {args[0]} {args[1]} failed ({result.returncode}): {message}')
    return result.stdout.strip()


def main(source: Path, expected_version: str) -> None:
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', expected_version):
        raise ValueError('Invalid expected candidate version')
    if tuple(map(int, expected_version.split('.'))) <= (3, 19, 9):
        raise ValueError('The candidate must be newer than the published baseline')
    workspace = Path(os.environ.get('GITHUB_WORKSPACE', '')).resolve()
    temporary = Path(os.environ.get('RUNNER_TEMP', '')).resolve()
    ci_paths(source, workspace, temporary)
    source = source.resolve()
    # This test may never reuse arbitrary credentials or a nonlocal database.
    if (wp(source, 'config', 'get', 'DB_HOST') != '127.0.0.1:3306'
            or wp(source, 'config', 'get', 'DB_USER') != 'root'
            or wp(source, 'config', 'get', 'DB_NAME') != 'wordpress'):
        raise RuntimeError('Expected the isolated Actions MySQL fixture')
    candidate = workspace / 'dist' / f'{PLUGIN}.zip'
    digest_line = candidate.with_suffix('.zip.sha256').read_text().strip()
    if (not re.fullmatch(r'[a-f0-9]{64}  wordpress-calendar-booking\.zip', digest_line)
            or hashlib.sha256(candidate.read_bytes()).hexdigest() != digest_line[:64]):
        raise ValueError('Candidate checksum mismatch')
    candidate_files = archive_files(candidate)
    header = candidate_files['wordpress-calendar-booking.php'].decode('utf-8')
    if not re.search(r'^ \* Version: ' + re.escape(expected_version) + '$', header, re.M):
        raise ValueError('Candidate header differs from the fresh-install version')
    fixture = Path(__file__).with_name('stable-upgrade-fixture.php').resolve()
    with tempfile.TemporaryDirectory(prefix='wpcb-stable-upgrade-', dir=temporary) as directory:
        work = Path(directory)
        baseline = work / 'baseline.zip'
        request = urllib.request.Request(BASELINE_URL, headers={'User-Agent': 'WPCB-upgrade-acceptance'})
        with urllib.request.urlopen(request, timeout=60) as response:
            data = response.read(MAX_ARCHIVE + 1)
        if len(data) > MAX_ARCHIVE or hashlib.sha256(data).hexdigest() != BASELINE_SHA256:
            raise ValueError('Published 3.19.9 baseline digest mismatch')
        baseline.write_bytes(data)
        archive_files(baseline)
        print('PASS: actual published 3.19.9 matches its pinned release digest', flush=True)
        root = work / 'wordpress'
        shutil.copytree(source, root, ignore=shutil.ignore_patterns('wp-content', '.git'))
        (root / 'wp-content' / 'plugins').mkdir(parents=True)
        shutil.copytree(source / 'wp-content' / 'themes', root / 'wp-content' / 'themes')
        database = 'wpcb_upgrade_' + os.urandom(8).hex()
        wp(root, 'config', 'set', 'DB_NAME', database, '--type=constant')
        wp(root, 'config', 'set', 'DISABLE_WP_CRON', 'true', '--raw', '--type=constant')
        if wp(root, 'config', 'get', 'DB_NAME') != database:
            raise RuntimeError('Refusing to use the original CI database')
        wp(root, 'db', 'create')
        try:
            wp(root, 'core', 'install', '--url=http://127.0.0.1:8089', '--title=WPCB-Upgrade',
               '--admin_user=admin', '--admin_password=integration-only',
               '--admin_email=upgrade@example.invalid', '--skip-email')
            wp(root, 'plugin', 'install', str(baseline), '--activate')
            baseline_count = verify_installation(baseline, root / 'wp-content' / 'plugins' / PLUGIN)
            print(f'PASS: all {baseline_count} installed baseline files match the published archive', flush=True)
            print(wp(root, 'eval-file', str(fixture), extra={'WPCB_UPGRADE_PHASE': 'seed'}), flush=True)
            wp(root, 'plugin', 'install', str(candidate), '--force', '--activate')
            print(wp(root, 'eval-file', str(fixture), extra={
                'WPCB_UPGRADE_PHASE': 'assert', 'WPCB_UPGRADE_VERSION': expected_version}), flush=True)
            count = verify_installation(candidate, root / 'wp-content' / 'plugins' / PLUGIN)
            print(f'PASS: all {count} installed upgrade files match the exact candidate archive', flush=True)
        finally:
            # Remove only the database this invocation created, never the caller's.
            if (not re.fullmatch(r'wpcb_upgrade_[a-f0-9]{16}', database)
                    or wp(root, 'config', 'get', 'DB_NAME') != database):
                raise RuntimeError('Refusing unsafe acceptance database cleanup')
            wp(root, 'db', 'drop', '--yes')
    print('PASS: published-baseline upgrade acceptance completed; original fresh fixture untouched', flush=True)


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('Usage: upgrade-from-stable.py FRESH_CI_WP_ROOT EXPECTED_VERSION')
    main(Path(sys.argv[1]), sys.argv[2])
