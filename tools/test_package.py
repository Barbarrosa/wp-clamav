"""Exercise deterministic WordPress distribution and exact release metadata."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
import zipfile


class PackageTests(unittest.TestCase):
    def test_zip_is_deterministic_and_contains_source_license(self):
        script = Path(__file__).with_name('package.py')
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            source = root / 'plugin'
            source.mkdir()
            (source / 'src').mkdir()
            (source / 'assets').mkdir()
            (source / 'wp-clamav.php').write_text('<?php\n/**\n * Version: 0.1.0\n */\n')
            (source / 'src/Example.php').write_text('<?php echo "fixture";\n')
            (source / 'LICENSE').write_text('GPL-2.0-or-later distribution fixture\n')
            report = root / 'qualification.json'
            report.write_text(json.dumps({'integration_sha': 'a' * 40, 'passed': True}))
            outputs = []
            for name in ('one', 'two'):
                output = root / name
                subprocess.run([sys.executable, str(script), '--source', str(source), '--output', str(output),
                    '--qualification', str(report), '--repository', 'example/plugin', '--integration-sha', 'a' * 40,
                    '--sequence', '1', '--expires-at', str(int(time.time()) + 3600)], check=True, capture_output=True)
                outputs.append(output)
            first, second = [p / 'wp-clamav-0.1.0.zip' for p in outputs]
            self.assertEqual(first.read_bytes(), second.read_bytes())
            with zipfile.ZipFile(first) as archive:
                self.assertIn('wp-clamav/LICENSE', archive.namelist())
                self.assertIn('wp-clamav/src/Example.php', archive.namelist())
            manifest = json.loads((outputs[0] / 'manifest.json').read_text())
            self.assertEqual(manifest['files'][0]['sha256'], hashlib.sha256(first.read_bytes()).hexdigest())
            self.assertEqual(manifest['files'][0]['mode'], '0400')
            self.assertTrue(manifest['experimental'])
            self.assertEqual(manifest['tag'], 'v0.1.0')


if __name__ == '__main__':
    unittest.main()
