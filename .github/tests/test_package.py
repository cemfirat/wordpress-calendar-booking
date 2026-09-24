"""Dependency-free regression tests for the real release packager."""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock
import zipfile

SOURCE = Path(__file__).resolve().parents[2] / 'scripts/package.py'
spec = importlib.util.spec_from_file_location('wpcb_package', SOURCE)
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)


class PackageTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'source'
        self.root.mkdir()
        self.patch = mock.patch.multiple(package, ROOT=self.root,
            MAIN=self.root / 'wordpress-calendar-booking.php',
            README=self.root / 'readme.txt', LICENSE=self.root / 'LICENSE')
        self.patch.start()
        self.addCleanup(self.patch.stop)
        for name in package.RUNTIME_REQUIRED:
            self.write(name, 'fixture\n')
        self.write('LICENSE', 'GNU GENERAL PUBLIC LICENSE\n' + 'x' * 10001)
        self.write('wordpress-calendar-booking.php', "<?php\n/*\n * Version: 3.19.4\n * Requires at least: 6.5\n * Requires PHP: 8.0\n */\ndefine('WPCB_VERSION', '3.19.4');\n")
        self.write('readme.txt', 'Stable tag: 3.19.4\n')
        self.output = self.root / 'dist/plugin.zip'

    def write(self, name, text):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)
        return path

    def names(self):
        with zipfile.ZipFile(self.output) as archive:
            return archive.namelist()

    def test_archive_is_sorted_canonical_and_has_fixed_metadata(self):
        package.build(self.output)
        with zipfile.ZipFile(self.output) as archive:
            self.assertIsNone(archive.testzip())
            self.assertEqual(archive.namelist(), sorted(archive.namelist()))
            for info in archive.infolist():
                self.assertTrue(info.filename.startswith('wordpress-calendar-booking/'))
                self.assertEqual(info.date_time, (2026, 1, 1, 0, 0, 0))
                self.assertEqual(info.external_attr >> 16, 0o100644)

    def test_two_builds_have_identical_bytes(self):
        first = package.build(self.output)
        data = self.output.read_bytes()
        self.assertEqual(first, package.build(self.output))
        self.assertEqual(data, self.output.read_bytes())

    def test_source_mtime_does_not_change_archive(self):
        package.build(self.output)
        before = self.output.read_bytes()
        os.utime(self.root / 'README.md', (1900000000, 1900000000))
        package.build(self.output)
        self.assertEqual(before, self.output.read_bytes())

    def test_relative_output_never_archives_itself_on_rebuild(self):
        cwd = Path.cwd()
        try:
            os.chdir(self.root)
            first = package.build(Path('custom.zip'))
            before = Path('custom.zip').read_bytes()
            self.assertEqual(first, package.build(Path('custom.zip')))
            self.assertEqual(before, Path('custom.zip').read_bytes())
            with zipfile.ZipFile('custom.zip') as archive:
                self.assertNotIn('wordpress-calendar-booking/custom.zip', archive.namelist())
        finally:
            os.chdir(cwd)

    def test_output_checksum_is_not_packaged(self):
        self.output = self.root / 'custom.zip'
        self.write('custom.zip.sha256', 'previous checksum')
        package.build(self.output)
        self.assertNotIn('wordpress-calendar-booking/custom.zip.sha256', self.names())

    def test_development_directories_and_secrets_are_excluded(self):
        for name in ['.git/config', '.github/workflows/x.yml', 'tests/test.php',
                     'scripts/tool.py', 'node_modules/pkg/index.js', 'dist/old.zip',
                     '_bootstrap/config.php', '.env.production', 'debug.log']:
            self.write(name, 'MUST NOT SHIP')
        package.build(self.output)
        with zipfile.ZipFile(self.output) as archive:
            self.assertTrue(all(b'MUST NOT SHIP' not in archive.read(n) for n in archive.namelist()))

    def test_nested_development_directories_are_pruned(self):
        for name in ['vendor/pkg/tests/test.php', 'vendor/pkg/.git/config',
                     'includes/__pycache__/cache.pyc', 'vendor/pkg/node_modules/x.js']:
            self.write(name, 'MUST NOT SHIP')
        package.build(self.output)
        self.assertFalse(any('/tests/' in n or '/.git/' in n or '/node_modules/' in n for n in self.names()))

    def test_missing_runtime_file_fails_before_output_changes(self):
        package.build(self.output)
        before = self.output.read_bytes()
        (self.root / 'vendor/autoload.php').unlink()
        with self.assertRaises(SystemExit):
            package.build(self.output)
        self.assertEqual(before, self.output.read_bytes())

    def test_header_constant_mismatch_is_rejected(self):
        p = self.root / 'wordpress-calendar-booking.php'
        p.write_text(p.read_text().replace("define('WPCB_VERSION', '3.19.4')", "define('WPCB_VERSION', '3.19.3')"))
        with self.assertRaises(SystemExit):
            package.build(self.output)
        self.assertFalse(self.output.exists())

    def test_stable_tag_mismatch_is_rejected(self):
        self.write('readme.txt', 'Stable tag: 3.19.3\n')
        with self.assertRaises(SystemExit):
            package.build(self.output)

    def test_symlink_file_is_rejected_without_reading_its_target(self):
        secret = Path(self.temp.name) / 'outside.txt'
        secret.write_text('EXTERNAL FIXTURE')
        (self.root / 'leaked.txt').symlink_to(secret)
        with self.assertRaises(SystemExit):
            package.build(self.output)
        self.assertFalse(self.output.exists())

    def test_symlink_directory_is_rejected(self):
        outside = Path(self.temp.name) / 'outside'
        outside.mkdir()
        (outside / 'secret.txt').write_text('EXTERNAL FIXTURE')
        (self.root / 'linked').symlink_to(outside, target_is_directory=True)
        with self.assertRaises(SystemExit):
            package.build(self.output)

    def test_output_symlink_does_not_overwrite_target(self):
        target = Path(self.temp.name) / 'outside.zip'
        target.write_bytes(b'KEEP')
        self.output.parent.mkdir()
        self.output.symlink_to(target)
        with self.assertRaises(SystemExit):
            package.build(self.output)
        self.assertEqual(target.read_bytes(), b'KEEP')

    def test_non_zip_output_does_not_overwrite_source(self):
        target = self.root / 'README.md'
        before = target.read_bytes()
        with self.assertRaises(SystemExit):
            package.build(target)
        self.assertEqual(target.read_bytes(), before)

    def test_read_failure_preserves_previous_archive_and_cleans_temp(self):
        package.build(self.output)
        before = self.output.read_bytes()
        read = Path.read_bytes
        def fail(path):
            if path == self.root / 'README.md':
                raise OSError('simulated read failure')
            return read(path)
        with mock.patch.object(Path, 'read_bytes', fail):
            with self.assertRaises(OSError):
                package.build(self.output)
        self.assertEqual(before, self.output.read_bytes())
        self.assertEqual(list(self.output.parent.iterdir()), [self.output])

    def test_unsafe_archive_filename_is_rejected(self):
        self.write('includes/bad\\name.php', '<?php')
        with self.assertRaises(SystemExit):
            package.build(self.output)


if __name__ == '__main__':
    unittest.main()
