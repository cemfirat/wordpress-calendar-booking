"""Run the dependency-free PHP lifecycle contracts in the existing preflight gate."""
import pathlib
import shutil
import subprocess
import unittest


class BookingTransitionContracts(unittest.TestCase):
    def test_serialization_and_atomic_batch_contracts(self):
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP is required; lifecycle contracts must not be skipped")
        script = pathlib.Path(__file__).with_name("booking-transition-contract.php")
        result = subprocess.run([php, str(script)], capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("20 contracts; 0 failed.", result.stdout)
