"""Run real PHP refund code with local HTTP/config/SQL doubles, without skips."""
import os
import pathlib
import shutil
import subprocess
import sys
import unittest


class RefundProviderContracts(unittest.TestCase):
    def test_refund_states_retries_and_accounting(self):
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP is required; refund contracts must not be skipped")
        script = pathlib.Path(__file__).with_name("refund-provider-contract.php")
        result = subprocess.run(
            [php, str(script)], capture_output=True, text=True, timeout=60,
            env={**os.environ, "WPCB_CONTRACT_PYTHON": sys.executable},
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("34 contracts; 0 failed.", result.stdout)
