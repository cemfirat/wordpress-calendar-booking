import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('preflight', ROOT / 'scripts/preflight.py')
preflight = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preflight)


class PreflightTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)

    def put(self, name, content):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)
        return path

    def test_valid_files_in_all_supported_languages(self):
        for name, content in [('a.php', '<?php echo 1;'), ('a.mjs', 'export const a = 1;'),
                              ('a.js', 'const b = 2;'), ('a.py', 'x = 1\n'),
                              ('a.sh', 'echo ok\n'), ('a.json', '{"x":1}')]:
            self.put(name, content)
        counts, errors = preflight.run(self.root)
        self.assertEqual(sum(counts.values()), 6)
        self.assertEqual(errors, [])

    def test_all_broken_files_are_reported_not_just_the_first(self):
        for name, content in [('a.php', '<?php syntax invalid!'), ('a.mjs', 'export const = ;'),
                              ('a.py', 'def :'), ('a.sh', 'if then'), ('a.json', '{broken}')]:
            self.put(name, content)
        _, errors = preflight.run(self.root)
        self.assertEqual(len(errors), 5)

    def test_duplicate_json_keys_are_rejected(self):
        self.put('block.json', '{"version":"old","version":"new"}')
        _, errors = preflight.run(self.root)
        self.assertIn('Duplicate JSON key', errors[0])

    def test_generated_and_dependency_trees_are_excluded(self):
        self.put('valid.py', 'x = 1')
        for directory in ['vendor', 'node_modules', '.git', 'dist', '_bootstrap']:
            self.put(directory + '/broken.py', 'def :')
        counts, errors = preflight.run(self.root)
        self.assertEqual(sum(counts.values()), 1)
        self.assertEqual(errors, [])

    def test_empty_tree_does_not_pass(self):
        _, errors = preflight.run(self.root)
        self.assertTrue(errors)

    def test_missing_tool_is_an_error_not_a_skip(self):
        self.put('a.php', '<?php echo 1;')
        with mock.patch.object(preflight.subprocess, 'run', side_effect=FileNotFoundError('php')):
            _, errors = preflight.run(self.root)
        self.assertEqual(len(errors), 1)


if __name__ == '__main__':
    unittest.main()
