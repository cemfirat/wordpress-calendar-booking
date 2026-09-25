from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]

class BookingEntryTests(unittest.TestCase):
    def test_php_configuration_and_expiry_contracts(self):
        subprocess.run(['php', '.github/tests/booking-entry-contract.php'], cwd=ROOT, check=True, timeout=30)

    def test_frontend_response_ordering(self):
        subprocess.run(['node', '--test', '.github/tests/booking-entry.test.mjs'], cwd=ROOT, check=True, timeout=30)
