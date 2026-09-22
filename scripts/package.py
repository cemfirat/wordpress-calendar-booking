#!/usr/bin/env python3
"""Build the canonical reproducible WordPress Calendar Booking release ZIP."""
from __future__ import annotations

import hashlib
import pathlib
import re
import sys
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
PLUGIN_DIR = "wordpress-calendar-booking"

EXCLUDED_TOP_LEVEL = {
    ".git",
    ".github",
    "node_modules",
    "tests",
    "scripts",
    "docs",
    "dist",
}
EXCLUDED_FILES = {
    ".gitignore",
    "package.json",
    "package-lock.json",
    "composer.json",
    "composer.lock",
}

main_file = ROOT / "cemb-calendar-booking.php"
header = main_file.read_text(encoding="utf-8")
version_match = re.search(r"^ \* Version: (\d+\.\d+\.\d+)$", header, re.M)
if not version_match:
    raise SystemExit("Plugin Version header is missing or invalid.")
version = version_match.group(1)

if f"define('CEMB_VERSION', '{version}');" not in header:
    raise SystemExit("CEMB_VERSION does not match the plugin header.")

readme = (ROOT / "readme.txt").read_text(encoding="utf-8")
if f"Stable tag: {version}\n" not in readme:
    raise SystemExit("readme.txt Stable tag does not match the plugin version.")

license_text = (ROOT / "LICENSE").read_text(encoding="utf-8")
if "GNU GENERAL PUBLIC LICENSE" not in license_text or len(license_text) < 10000:
    raise SystemExit("Full GPL license is missing.")

required_runtime = [
    ROOT / "vendor" / "autoload.php",
    ROOT / "assets" / "vendor" / "uikit" / "uikit.min.css",
    ROOT / "assets" / "vendor" / "uikit" / "uikit.min.js",
    ROOT / "assets" / "vendor" / "uikit" / "uikit-icons.min.js",
    ROOT / "includes" / "Update" / "GitHubUpdater.php",
]
for path in required_runtime:
    if not path.is_file() or path.stat().st_size == 0:
        raise SystemExit(f"Required runtime asset missing: {path.relative_to(ROOT)}")

files: list[pathlib.Path] = []
for path in ROOT.rglob("*"):
    if not path.is_file():
        continue
    relative = path.relative_to(ROOT)
    if relative.parts[0] in EXCLUDED_TOP_LEVEL:
        continue
    if len(relative.parts) == 1 and relative.name in EXCLUDED_FILES:
        continue
    files.append(relative)

archive = pathlib.Path(
    sys.argv[1] if len(sys.argv) > 1 else ROOT / "dist" / "wordpress-calendar-booking.zip"
)
archive.parent.mkdir(parents=True, exist_ok=True)

fixed_time = (2026, 1, 1, 0, 0, 0)
with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as out:
    for relative in sorted(files, key=lambda p: p.as_posix()):
        info = zipfile.ZipInfo(f"{PLUGIN_DIR}/{relative.as_posix()}", fixed_time)
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        out.writestr(info, (ROOT / relative).read_bytes())

with zipfile.ZipFile(archive) as built:
    bad = built.testzip()
    if bad:
        raise SystemExit(f"Corrupt ZIP entry: {bad}")
    names = built.namelist()
    forbidden = ("/tests/", "/node_modules/", "/.git/", "/.github/", "/scripts/")
    if any(any(marker in f"/{name}" for marker in forbidden) for name in names):
        raise SystemExit("Development-only content leaked into the distribution.")
    expected = {
        f"{PLUGIN_DIR}/cemb-calendar-booking.php",
        f"{PLUGIN_DIR}/vendor/autoload.php",
        f"{PLUGIN_DIR}/assets/vendor/uikit/uikit.min.css",
        f"{PLUGIN_DIR}/LICENSE",
    }
    if not expected.issubset(names):
        raise SystemExit("Distribution is incomplete.")

digest = hashlib.sha256(archive.read_bytes()).hexdigest()
print(f"Built WordPress Calendar Booking {version}: {archive}")
print(f"Files: {len(files)}")
print(f"SHA256: {digest}")
