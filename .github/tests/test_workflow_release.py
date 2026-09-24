"""Narrow wiring checks, not a replacement for a YAML parser or live CI."""
from pathlib import Path
import re
import unittest

SOURCE = (Path(__file__).resolve().parents[1] / 'workflows/ci.yml').read_text()


def job(name):
    match = re.search(r'^  ' + re.escape(name) + r':\n([\s\S]*?)(?=^  [a-z_]+:|\Z)', SOURCE, re.M)
    if not match:
        raise AssertionError('Missing job: ' + name)
    return match.group(1)


class WorkflowReleaseTests(unittest.TestCase):
    def test_offline_checks_precede_dependency_installation(self):
        source = job('dependency_locks')
        self.assertLess(source.index('python3 -B scripts/preflight.py'), source.index('composer validate'))
        self.assertIn("python3 -B -m unittest discover -s .github/tests -p 'test_*.py' -v", source)

    def test_integration_and_package_depend_on_preflight(self):
        for name in ['wordpress', 'package']:
            with self.subTest(job=name):
                self.assertIn('    needs: [dependency_locks]\n', job(name))

    def test_package_is_compared_with_a_second_build(self):
        source = job('package')
        self.assertIn('python3 scripts/package.py "$RUNNER_TEMP/wpcb-repeat.zip"', source)
        self.assertIn('cmp dist/wordpress-calendar-booking.zip "$RUNNER_TEMP/wpcb-repeat.zip"', source)

    def test_public_verification_occurs_after_version_resolution(self):
        source = job('production_update')
        self.assertLess(source.index('name: Resolve updater versions'), source.index('verify_public_release.py download'))
        self.assertLess(source.index('verify_public_release.py download'), source.index('name: Build synthetic'))
        self.assertIn('download "$TARGET_VERSION" "$RUNNER_TEMP/wpcb-public-release"', source)

    def test_installed_bytes_are_checked_against_public_not_dist(self):
        source = job('production_update')
        command = 'python3 scripts/verify_public_release.py installed "$TARGET_VERSION" "$RUNNER_TEMP/wpcb-public-release/wordpress-calendar-booking.zip" "$WP_ROOT/wp-content/plugins/wordpress-calendar-booking"'
        self.assertIn(command, source)
        self.assertLess(source.index('wp plugin update wordpress-calendar-booking'), source.index(command))
        self.assertLess(source.index(command), source.index('echo "PASS: synthetic'))

    def test_published_releases_keep_the_no_overwrite_guard(self):
        source = job('release')
        self.assertIn('Release $RELEASE_TAG already published; keeping it immutable.', source)
        self.assertLess(source.index('exit 0'), source.index('gh release upload'))


if __name__ == '__main__':
    unittest.main()
