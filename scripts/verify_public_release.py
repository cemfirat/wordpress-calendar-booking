#!/usr/bin/env python3
"""Verify the published asset, then the bytes actually installed by WordPress.

No comparison with the current build is made: an already-published version
keeps its original asset, including its original Composer commit metadata.
"""
from __future__ import annotations

import argparse
import hashlib
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import zipfile

REPOSITORY = 'cemfirat/wordpress-calendar-booking'
SLUG = 'wordpress-calendar-booking'
ASSET = SLUG + '.zip'
MAX_ZIP_BYTES = 100 * 1024 * 1024
MAX_EXPANDED_BYTES = 256 * 1024 * 1024
MAX_FILES = 10000


def digest(path: Path) -> str:
    result = hashlib.sha256()
    with path.open('rb') as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b''):
            result.update(chunk)
    return result.hexdigest()


def verified_files(archive_path: Path, version: str) -> dict[str, str]:
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', version):
        raise ValueError('Invalid stable release version.')
    checksum = archive_path.with_name(ASSET + '.sha256')
    if archive_path.is_symlink() or checksum.is_symlink():
        raise ValueError('Release assets must not be symlinks.')
    if archive_path.stat().st_size > MAX_ZIP_BYTES or checksum.stat().st_size > 512:
        raise ValueError('Release asset exceeds the verification size limit.')
    match = re.fullmatch(r'([0-9a-fA-F]{64}) [ *]' + re.escape(ASSET) + r'\r?\n?',
                        checksum.read_text(encoding='ascii'))
    if not match or digest(archive_path) != match.group(1).lower():
        raise ValueError('Public release checksum is missing, malformed or does not match.')

    files: dict[str, str] = {}
    with zipfile.ZipFile(archive_path) as archive:
        entries = archive.infolist()
        if len(entries) > MAX_FILES or sum(item.file_size for item in entries) > MAX_EXPANDED_BYTES:
            raise ValueError('Release archive exceeds the verification work limit.')
        seen = set()
        for item in entries:
            name = item.filename
            parts = name.rstrip('/').split('/')
            mode = item.external_attr >> 16
            if (len(parts) < 2 or parts[0] != SLUG
                    or any(part in ('', '.', '..') for part in parts)
                    or any(ord(char) < 32 or char in '\\:' for char in name)
                    or stat.S_ISLNK(mode) or name.rstrip('/') in seen):
                raise ValueError('Unsafe or duplicate entry in public release archive.')
            seen.add(name.rstrip('/'))
            if not item.is_dir():
                files['/'.join(parts[1:])] = hashlib.sha256(archive.read(item)).hexdigest()
        header_name = SLUG + '/' + SLUG + '.php'
        header = archive.read(header_name).decode('utf-8')
        headers = re.findall(r'^ \* Version: ([0-9.]+)$', header, re.M)
        if headers != [version] or f"define('WPCB_VERSION', '{version}');" not in header:
            raise ValueError('Public asset version does not match its release tag.')
    if not files:
        raise ValueError('Public release archive contains no files.')
    return files


def download(version: str, destination: Path) -> Path:
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', version):
        raise ValueError('Invalid stable release version.')
    if destination.is_symlink():
        raise ValueError('Verification directory must not be a symlink.')
    destination.mkdir(parents=True, exist_ok=True)
    if any(destination.iterdir()):
        raise ValueError('Verification directory must be empty; stale assets are not accepted.')
    subprocess.run(['gh', 'release', 'download', 'v' + version, '--repo', REPOSITORY,
                    '--pattern', ASSET, '--pattern', ASSET + '.sha256',
                    '--dir', str(destination)], check=True, timeout=180)
    artifact = destination / ASSET
    verified_files(artifact, version)
    # Validate the downloaded PUBLIC bytes, not the local CI build in dist/.
    subprocess.run(['gh', 'attestation', 'verify', str(artifact), '--repo', REPOSITORY,
                    '--signer-workflow', REPOSITORY + '/.github/workflows/ci.yml',
                    '--source-ref', 'refs/heads/main', '--deny-self-hosted-runners'],
                   check=True, timeout=180)
    return artifact


def installed(archive_path: Path, directory: Path, version: str) -> int:
    expected = verified_files(archive_path, version)
    if directory.is_symlink() or not directory.is_dir():
        raise ValueError('Installed plugin directory is missing or is a symlink.')
    actual = {}
    for base, dirs, names in os.walk(directory, followlinks=False):
        for name in dirs + names:
            path = Path(base) / name
            if path.is_symlink():
                raise ValueError('Symlink found in the installed plugin.')
        for name in names:
            path = Path(base) / name
            if not path.is_file():
                raise ValueError('Non-regular file found in the installed plugin.')
            actual[path.relative_to(directory).as_posix()] = digest(path)
    if actual != expected:
        missing = len(expected.keys() - actual.keys())
        extra = len(actual.keys() - expected.keys())
        changed = sum(actual[name] != expected[name] for name in actual.keys() & expected.keys())
        raise ValueError(f'Installed files differ from verified public release: {missing} missing, {extra} extra, {changed} changed.')
    return len(actual)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    fetch = commands.add_parser('download')
    fetch.add_argument('version')
    fetch.add_argument('directory', type=Path)
    check = commands.add_parser('installed')
    check.add_argument('version')
    check.add_argument('archive', type=Path)
    check.add_argument('directory', type=Path)
    args = parser.parse_args()
    try:
        if args.command == 'download':
            download(args.version, args.directory)
            print('PASS: public release checksum, version and trusted main-workflow attestation verified.')
        else:
            count = installed(args.archive, args.directory, args.version)
            print(f'PASS: all {count} installed files match the verified public release.')
    except (OSError, ValueError, KeyError, zipfile.BadZipFile, subprocess.SubprocessError) as error:
        print(f'Release verification failed: {error}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
