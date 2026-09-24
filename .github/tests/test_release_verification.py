"""Exercise public-asset verification offline; cryptography remains gh's job."""
import hashlib
import importlib.util
import io
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest
from unittest import mock
import warnings
import zipfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('verify', ROOT / 'scripts/verify_public_release.py')
verify = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verify)
VERSION = '3.19.4'
HEADER = "<?php\n/*\n * Version: 3.19.4\n */\ndefine('WPCB_VERSION', '3.19.4');\n"


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.asset = self.root / verify.ASSET
        self.entries = [(verify.SLUG + '/' + verify.SLUG + '.php', HEADER),
                        (verify.SLUG + '/includes/example.php', '<?php // fixture\n')]
        self.build()

    def build(self):
        with warnings.catch_warnings():
            warnings.simplefilter('ignore', UserWarning)
            with zipfile.ZipFile(self.asset, 'w') as archive:
                for name, content in self.entries:
                    archive.writestr(name, content)
        self.checksum = self.asset.with_name(verify.ASSET + '.sha256')
        self.checksum.write_text(verify.digest(self.asset) + '  ' + verify.ASSET + '\n')

    def install(self):
        with zipfile.ZipFile(self.asset) as archive:
            archive.extractall(self.root / 'installed')
        return self.root / 'installed' / verify.SLUG

    def test_valid_release_and_installation(self):
        self.assertEqual(len(verify.verified_files(self.asset, VERSION)), 2)
        self.assertEqual(verify.installed(self.asset, self.install(), VERSION), 2)

    def test_changed_archive_is_rejected_before_attestation(self):
        self.asset.write_bytes(self.asset.read_bytes() + b'tamper')
        with self.assertRaisesRegex(ValueError, 'checksum'):
            verify.verified_files(self.asset, VERSION)

    def test_checksum_cannot_select_another_filename(self):
        self.checksum.write_text(verify.digest(self.asset) + '  another.zip\n')
        with self.assertRaisesRegex(ValueError, 'checksum'):
            verify.verified_files(self.asset, VERSION)

    def test_extra_checksum_lines_are_rejected(self):
        self.checksum.write_text(self.checksum.read_text() * 2)
        with self.assertRaisesRegex(ValueError, 'checksum'):
            verify.verified_files(self.asset, VERSION)

    def test_wrong_asset_version_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'version'):
            verify.verified_files(self.asset, '3.19.5')

    def test_invalid_version_is_rejected_before_network_access(self):
        with mock.patch.object(verify.subprocess, 'run') as run:
            for version in ['--help', 'v3.19.4', '../3.19.4', '3.19.4\n']:
                with self.subTest(version=version), self.assertRaises(ValueError):
                    verify.download(version, self.root / 'download')
            run.assert_not_called()

    def test_unsafe_and_duplicate_archive_paths_are_rejected(self):
        for name in ['../outside', '/absolute', verify.SLUG + '/../outside',
                     verify.SLUG + '/a\\b.php', verify.SLUG + '/./foo.php',
                     verify.SLUG + '//foo.php', self.entries[0][0], 'another-plugin/plugin.php']:
            with self.subTest(name=name):
                self.entries.append((name, 'fixture'))
                self.build()
                with self.assertRaisesRegex(ValueError, 'Unsafe|duplicate'):
                    verify.verified_files(self.asset, VERSION)
                self.entries.pop()

    def test_symlink_archive_entry_is_rejected(self):
        info = zipfile.ZipInfo(verify.SLUG + '/link')
        info.create_system = 3
        info.external_attr = (stat.S_IFLNK | 0o777) << 16
        self.entries.append((info, '../../outside'))
        self.build()
        with self.assertRaises(ValueError):
            verify.verified_files(self.asset, VERSION)

    def test_missing_installed_file_is_rejected(self):
        directory = self.install()
        (directory / 'includes/example.php').unlink()
        with self.assertRaisesRegex(ValueError, '1 missing'):
            verify.installed(self.asset, directory, VERSION)

    def test_modified_installed_file_is_rejected(self):
        directory = self.install()
        (directory / 'includes/example.php').write_text('different')
        with self.assertRaisesRegex(ValueError, '1 changed'):
            verify.installed(self.asset, directory, VERSION)

    def test_unexpected_installed_file_is_rejected(self):
        directory = self.install()
        (directory / 'unexpected.php').write_text('unexpected')
        with self.assertRaisesRegex(ValueError, '1 extra'):
            verify.installed(self.asset, directory, VERSION)

    def test_installed_symlink_is_rejected(self):
        directory = self.install()
        (directory / 'extra').symlink_to(self.asset)
        with self.assertRaisesRegex(ValueError, 'Symlink'):
            verify.installed(self.asset, directory, VERSION)

    def test_download_verifies_public_path_with_expected_identity(self):
        destination = self.root / 'public'
        calls = []
        def fake_run(command, **kwargs):
            calls.append(command)
            self.assertTrue(kwargs['check'])
            self.assertEqual(kwargs['timeout'], 180)
            if command[1:3] == ['release', 'download']:
                shutil.copy2(self.asset, destination / verify.ASSET)
                shutil.copy2(self.checksum, destination / self.checksum.name)
            return subprocess.CompletedProcess(command, 0)
        with mock.patch.object(verify.subprocess, 'run', side_effect=fake_run):
            result = verify.download(VERSION, destination)
        self.assertEqual(result, destination / verify.ASSET)
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[0][1:4], ['release', 'download', 'v' + VERSION])
        self.assertEqual(calls[1][1:4], ['attestation', 'verify', str(result)])
        self.assertIn(verify.REPOSITORY + '/.github/workflows/ci.yml', calls[1])
        self.assertIn('refs/heads/main', calls[1])
        self.assertIn('--deny-self-hosted-runners', calls[1])
        self.assertNotIn('--clobber', calls[0])
        self.assertNotIn('--skip-existing', calls[0])

    def test_download_failure_does_not_proceed_to_attestation(self):
        with mock.patch.object(verify.subprocess, 'run', side_effect=subprocess.CalledProcessError(1, 'gh')) as run:
            with self.assertRaises(subprocess.CalledProcessError):
                verify.download(VERSION, self.root / 'public')
            self.assertEqual(run.call_count, 1)

    def test_attestation_failure_is_not_ignored(self):
        destination = self.root / 'public'
        def fake_run(command, **kwargs):
            if command[1] == 'release':
                shutil.copy2(self.asset, destination / verify.ASSET)
                shutil.copy2(self.checksum, destination / self.checksum.name)
                return subprocess.CompletedProcess(command, 0)
            raise subprocess.CalledProcessError(1, command)
        with mock.patch.object(verify.subprocess, 'run', side_effect=fake_run):
            with self.assertRaises(subprocess.CalledProcessError):
                verify.download(VERSION, destination)

    def test_stale_download_directory_is_not_reused(self):
        with mock.patch.object(verify.subprocess, 'run') as run:
            with self.assertRaisesRegex(ValueError, 'empty'):
                verify.download(VERSION, self.root)
            run.assert_not_called()

    def test_declared_expansion_limit_is_enforced(self):
        with mock.patch.object(verify, 'MAX_EXPANDED_BYTES', 1):
            with self.assertRaisesRegex(ValueError, 'work limit'):
                verify.verified_files(self.asset, VERSION)

    def test_same_version_local_build_is_not_used_as_public_digest(self):
        # The Composer source reference can differ on a later CI-only commit.
        published = self.asset.read_bytes()
        directory = self.install()
        self.entries.append((verify.SLUG + '/local-only.php', 'different local build'))
        self.build()
        local = self.asset.read_bytes()
        self.assertNotEqual(local, published)
        public_dir = self.root / 'public'
        public_dir.mkdir()
        public = public_dir / verify.ASSET
        public.write_bytes(published)
        (public_dir / self.checksum.name).write_text(hashlib.sha256(published).hexdigest() + '  ' + verify.ASSET + '\n')
        self.assertEqual(verify.installed(public, directory, VERSION), 2)


if __name__ == '__main__':
    unittest.main()
