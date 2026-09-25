from pathlib import Path
import subprocess
import unittest

class DemoCalendarTests(unittest.TestCase):
    def test_isolated_optional_demo_lifecycle(self):
        subprocess.run(['php', '.github/tests/demo-calendar-contract.php'], cwd=Path(__file__).resolve().parents[2], check=True, timeout=30)
