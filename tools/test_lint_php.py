"""Exercise the syntax gate with actual PHP child processes, including parse errors."""
import importlib.util
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).with_name('lint_php.py')
SPEC = importlib.util.spec_from_file_location('plugin_php_lint', SCRIPT)
LINT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(LINT)


@unittest.skipUnless(shutil.which('php'), 'Actual PHP lint tests require the pinned PHP builder')
class PhpLintTests(unittest.TestCase):
    def test_real_php_lints_a_nonempty_tree(self):
        with tempfile.TemporaryDirectory(prefix='php syntax gate ') as temporary:
            root = Path(temporary)
            (root / 'valid.php').write_text('<?php function fixture(): int { return 1; }\n')
            (root / 'nested').mkdir()
            (root / 'nested/also-valid.php').write_text('<?php echo "fixture";\n')
            self.assertEqual(LINT.lint(root), 2)

    def test_real_parse_error_propagates_and_cli_exits_nonzero(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / 'invalid.php').write_text('<?php function broken( {\n')
            with self.assertRaises(subprocess.CalledProcessError) as failure:
                LINT.lint(root)
            self.assertNotEqual(failure.exception.returncode, 0)
            cli = subprocess.run([sys.executable, str(SCRIPT), '--source', str(root)], capture_output=True, text=True)
            self.assertNotEqual(cli.returncode, 0)
            self.assertIn('PHP syntax gate failed', cli.stderr)

    def test_empty_selection_cannot_pass(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / 'README.txt').write_text('No PHP source here.\n')
            with self.assertRaisesRegex(RuntimeError, 'No PHP files'):
                LINT.lint(root)
            cli = subprocess.run([sys.executable, str(SCRIPT), '--source', str(root)], capture_output=True, text=True)
            self.assertNotEqual(cli.returncode, 0)
            self.assertIn('No PHP files', cli.stderr)


if __name__ == '__main__':
    unittest.main()
