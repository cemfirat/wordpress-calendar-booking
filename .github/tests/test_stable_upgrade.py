"""Offline tests of the upgrade harness; actual WordPress runs in the fresh ZIP gate."""
import hashlib
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch
import warnings
import zipfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('stable_upgrade', ROOT / 'tests/upgrade-from-stable.py')
upgrade = importlib.util.module_from_spec(spec)
spec.loader.exec_module(upgrade)


class StableUpgradeTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.archive = self.root / 'candidate.zip'
        self.installed = self.root / 'installed'
        self.installed.mkdir()
        self.header = b'<?php\n/**\n * Version: 3.19.8\n */\n'

    def package(self, extras=()):
        with warnings.catch_warnings():
            warnings.simplefilter('ignore', UserWarning)
            with zipfile.ZipFile(self.archive, 'w') as target:
                target.writestr('wordpress-calendar-booking/wordpress-calendar-booking.php', self.header)
                for name, content in extras:
                    target.writestr(name, content)

    def test_exact_installed_bytes_pass(self):
        self.package()
        (self.installed / 'wordpress-calendar-booking.php').write_bytes(self.header)
        self.assertEqual(upgrade.verify_installation(self.archive, self.installed), 1)

    def test_modified_missing_and_extra_files_are_rejected(self):
        self.package()
        with self.assertRaises(ValueError):
            upgrade.verify_installation(self.archive, self.installed)
        entry = self.installed / 'wordpress-calendar-booking.php'
        entry.write_bytes(self.header + b'changed')
        with self.assertRaises(ValueError):
            upgrade.verify_installation(self.archive, self.installed)
        entry.write_bytes(self.header)
        (self.installed / 'unexpected.php').write_text('<?php')
        with self.assertRaises(ValueError):
            upgrade.verify_installation(self.archive, self.installed)

    def test_unsafe_and_noncanonical_paths_are_rejected(self):
        for name in ('../outside', '/absolute', 'another-plugin/file',
                     'wordpress-calendar-booking/../outside',
                     'wordpress-calendar-booking//duplicate.php',
                     'wordpress-calendar-booking/./dot.php',
                     'wordpress-calendar-booking/a\\b.php',
                     'wordpress-calendar-booking/c:drive'):
            with self.subTest(name=name):
                self.package([(name, 'bad')])
                with self.assertRaises(ValueError):
                    upgrade.archive_files(self.archive)

    def test_duplicate_and_symlink_entries_are_rejected(self):
        self.package([('wordpress-calendar-booking/wordpress-calendar-booking.php', self.header)])
        with self.assertRaises(ValueError):
            upgrade.archive_files(self.archive)
        link = zipfile.ZipInfo('wordpress-calendar-booking/link')
        link.external_attr = (stat.S_IFLNK | 0o777) << 16
        self.package([(link, '/etc/passwd')])
        with self.assertRaises(ValueError):
            upgrade.archive_files(self.archive)

    def test_installed_symlink_is_rejected(self):
        self.package()
        (self.installed / 'wordpress-calendar-booking.php').symlink_to(self.archive)
        with self.assertRaises(ValueError):
            upgrade.verify_installation(self.archive, self.installed)

    def test_missing_entry_point_is_rejected(self):
        with zipfile.ZipFile(self.archive, 'w') as target:
            target.writestr('wordpress-calendar-booking/README.md', 'not a plugin')
        with self.assertRaises(ValueError):
            upgrade.archive_files(self.archive)

    def test_non_ci_environment_is_rejected_before_side_effects(self):
        with patch.dict(os.environ, {}, clear=True), patch.object(upgrade, 'wp') as command, patch.object(upgrade.urllib.request, 'urlopen') as network:
            with self.assertRaises(RuntimeError):
                upgrade.main(self.root, '3.19.8')
            command.assert_not_called()
            network.assert_not_called()

    def test_same_or_invalid_version_is_rejected(self):
        for version in ('3.19.7', '3.19.6', '3.19.8\n', 'v3.19.8', '../../x'):
            with self.subTest(version=version), patch.object(upgrade, 'wp') as command:
                with self.assertRaises(ValueError):
                    upgrade.main(self.root, version)
                command.assert_not_called()

    def test_nonlocal_database_is_rejected_before_download(self):
        source = self.root / 'wordpress'
        source.mkdir()
        (source / 'wp-config.php').write_text('<?php')
        workspace = self.root / 'repo'
        (workspace / 'dist').mkdir(parents=True)
        (workspace / 'dist/wordpress-calendar-booking.zip').write_bytes(b'placeholder')
        env = {'GITHUB_ACTIONS': 'true', 'RUNNER_TEMP': str(self.root), 'GITHUB_WORKSPACE': str(workspace)}
        with patch.dict(os.environ, env), patch.object(upgrade, 'wp', return_value='production.example') as command, patch.object(upgrade.urllib.request, 'urlopen') as network:
            with self.assertRaises(RuntimeError):
                upgrade.main(source, '3.19.7')
            self.assertEqual(command.call_count, 1)
            network.assert_not_called()

    def test_fresh_job_reaches_upgrade_acceptance_without_replacing_fresh_assertions(self):
        text = (ROOT / 'tests/release-fresh.php').read_text()
        self.assertIn('upgrade-from-stable.py', text)
        self.assertIn('proc_close', text)
        self.assertIn('Fresh release created table', text)
        self.assertIn('Release ZIP contains Composer runtime dependencies.', text)
        self.assertNotIn('continue-on-error', text)


if __name__ == '__main__':
    unittest.main()
