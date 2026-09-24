#!/usr/bin/env python3
"""Offline syntax checks. Run before pushing; CI runs the same command.

Requires Python 3.10+, PHP, Node and Bash. Does not install dependencies,
contact providers, or replace the WordPress/browser integration suites.
"""
from __future__ import annotations

import ast
from collections import Counter
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
EXCLUDED = {'.git', 'node_modules', 'vendor', 'dist', '_bootstrap', '__pycache__'}
SUFFIXES = {'.php', '.js', '.mjs', '.cjs', '.py', '.sh', '.json'}


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f'Duplicate JSON key: {key}')
        result[key] = value
    return result


def check_file(path: Path) -> None:
    suffix = path.suffix
    if suffix == '.py':
        ast.parse(path.read_text(encoding='utf-8'), filename=str(path))
        return
    if suffix == '.json':
        json.loads(path.read_text(encoding='utf-8'), object_pairs_hook=unique_object)
        return
    command = ['php', '-l'] if suffix == '.php' else (
        ['bash', '-n'] if suffix == '.sh' else ['node', '--check'])
    result = subprocess.run([*command, str(path)], capture_output=True, text=True, timeout=30)
    if result.returncode:
        raise ValueError((result.stderr or result.stdout).strip() or 'Syntax check failed.')


def run(root: Path = ROOT) -> tuple[Counter, list[str]]:
    counts = Counter()
    errors = []
    for base, dirs, names in os.walk(root, followlinks=False):
        dirs[:] = sorted(name for name in dirs if name not in EXCLUDED)
        for name in sorted(names):
            path = Path(base) / name
            if path.suffix not in SUFFIXES:
                continue
            try:
                if path.is_symlink():
                    raise ValueError('Source symlinks are not syntax-checked.')
                check_file(path)
                counts[path.suffix] += 1
            except (OSError, ValueError, SyntaxError, subprocess.SubprocessError) as error:
                errors.append(f'{path.relative_to(root)}: {error}')
    if not counts and not errors:
        errors.append('No source files found; an empty checkout is not a successful check.')
    return counts, errors


def main() -> int:
    counts, errors = run()
    for error in errors:
        print(error, file=sys.stderr)
    print('Syntax checked: ' + ', '.join(f'{count} {suffix}' for suffix, count in sorted(counts.items())))
    if errors:
        print(f'Preflight failed: {len(errors)} error(s).', file=sys.stderr)
        return 1
    print('PASS: offline syntax preflight.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
