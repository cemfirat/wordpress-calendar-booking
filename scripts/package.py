#!/usr/bin/env python3
"""Build the canonical reproducible WordPress Calendar Booking release ZIP."""
from __future__ import annotations

import hashlib
import os
import pathlib
import re
import shutil
import sys
import tempfile
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
PLUGIN_DIR = "wordpress-calendar-booking"
MAIN = ROOT / "wordpress-calendar-booking.php"
README = ROOT / "readme.txt"
LICENSE = ROOT / "LICENSE"

EXCLUDED_TOP = {
    ".git",
    ".github",
    "tests",
    "node_modules",
    "dist",
    "_bootstrap",
    "scripts",
}
EXCLUDED_FILES = {
    ".DS_Store",
    ".env",
    ".env.local",
    "package.json",
    "package-lock.json",
    "composer.json",
    "composer.lock",
}
EXCLUDED_SUFFIXES = {".log", ".pyc"}

RUNTIME_REQUIRED = [
    "wordpress-calendar-booking.php",
    "LICENSE",
    "readme.txt",
    "README.md",
    "CHANGELOG.md",
    "vendor/autoload.php",
    "assets/vendor/uikit/uikit.min.css",
    "assets/vendor/uikit/uikit.min.js",
    "assets/vendor/uikit/uikit-icons.min.js",
    "assets/vendor/uikit/VERSION",
]


def metadata() -> tuple[str, str, str]:
    text = MAIN.read_text(encoding="utf-8")
    version_match = re.search(r"^ \* Version: (\d+\.\d+\.\d+)$", text, re.M)
    wp_match = re.search(r"^ \* Requires at least: ([0-9.]+)$", text, re.M)
    php_match = re.search(r"^ \* Requires PHP: ([0-9.]+)$", text, re.M)
    if not version_match or not wp_match or not php_match:
        raise SystemExit("Missing release metadata in plugin header.")
    version = version_match.group(1)
    if f"define('WPCB_VERSION', '{version}');" not in text:
        raise SystemExit("WPCB_VERSION does not match plugin header.")
    readme = README.read_text(encoding="utf-8")
    if f"Stable tag: {version}\n" not in readme:
        raise SystemExit("readme.txt Stable tag does not match plugin version.")
    return version, wp_match.group(1), php_match.group(1)


def guard_runtime() -> None:
    license_text = LICENSE.read_text(encoding="utf-8")
    if "GNU GENERAL PUBLIC LICENSE" not in license_text or len(license_text) < 10000:
        raise SystemExit("Full GPL license is missing.")
    for relative in RUNTIME_REQUIRED:
        path = ROOT / relative
        if not path.is_file() or path.stat().st_size == 0:
            raise SystemExit(f"Missing runtime release file: {relative}")


def include(relative: pathlib.Path) -> bool:
    parts = relative.parts
    if not parts:
        return False
    if parts[0] in EXCLUDED_TOP:
        return False
    if relative.name in EXCLUDED_FILES or relative.suffix in EXCLUDED_SUFFIXES:
        return False
    if relative.name.startswith(".env"):
        return False
    return True


def build(output: pathlib.Path) -> tuple[str, int]:
    version, _, _ = metadata()
    guard_runtime()
    output.parent.mkdir(parents=True, exist_ok=True)

    files = [
        p for p in ROOT.rglob("*")
        if p.is_file() and include(p.relative_to(ROOT)) and p != output
    ]
    files.sort(key=lambda p: p.relative_to(ROOT).as_posix())

    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in files:
            relative = path.relative_to(ROOT).as_posix()
            info = zipfile.ZipInfo(f"{PLUGIN_DIR}/{relative}", (2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes())

    with zipfile.ZipFile(output) as archive:
        if archive.testzip() is not None:
            raise SystemExit("ZIP integrity check failed.")
        names = archive.namelist()
        if not names or not all(name.startswith(PLUGIN_DIR + "/") for name in names):
            raise SystemExit("ZIP does not have one canonical plugin root directory.")
        forbidden = [name for name in names if any(
            f"/{part}/" in name for part in (".git", ".github", "tests", "node_modules", "scripts")
        )]
        if forbidden:
            raise SystemExit("Development-only files leaked into ZIP: " + ", ".join(forbidden[:5]))

    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    return digest, len(files)


def main() -> None:
    version, wp_version, php_version = metadata()
    output = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "dist" / "wordpress-calendar-booking.zip"
    digest, count = build(output)
    print(f"Built WordPress Calendar Booking {version}")
    print(f"Requires WordPress: {wp_version}")
    print(f"Requires PHP: {php_version}")
    print(f"Files: {count}")
    print(f"SHA256: {digest}")
    print(f"ZIP: {output}")


if __name__ == "__main__":
    main()
