"""Reject WordPress's zero-exit HTML failures and require actual fixture completion."""
import importlib.util
import json
from pathlib import Path
import shutil
import unittest

SPEC = importlib.util.spec_from_file_location('plugin_wordpress_ci', Path(__file__).with_name('wordpress_ci.py'))
CI = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CI)


class FixtureReportTests(unittest.TestCase):
    def test_positive_expected_json_suite(self):
        report = {'suite': 'application-wordpress', 'result': 'passed', 'checks': 39}
        self.assertEqual(CI.require_php_suite(json.dumps(report), 'application-wordpress'), report)

    def test_html_invalid_json_empty_and_extra_output_fail(self):
        for output in ['', '<!DOCTYPE html><h1>Database Error</h1>', '{bad json}', '{}\n{}',
                       'Warning: fixture stopped\n{"suite":"application-wordpress","result":"passed","checks":39}']:
            with self.subTest(output=output), self.assertRaises(RuntimeError):
                CI.require_php_suite(output, 'application-wordpress')

    def test_skipped_wrong_suite_and_empty_checks_fail(self):
        valid = {'suite': 'application-wordpress', 'result': 'passed', 'checks': 39}
        for changes in [{'result': 'skipped'}, {'suite': 'other'}, {'checks': 0}, {'checks': True}, {'checks': '39'}]:
            with self.subTest(changes=changes), self.assertRaises(RuntimeError):
                CI.require_php_suite(json.dumps(dict(valid, **changes)), 'application-wordpress')

    def test_http_summary_requires_all_actual_passes(self):
        summary = '142 real HTTP checks passed (WordPress7.1.4/Forminator1.58.4; fixture backend only).'
        output = '\n'.join(['PASS actual fixture check'] * 142 + [summary])
        self.assertEqual(CI.require_http_suite(output), 142)
        expanded = '\n'.join(['PASS expanded fixture check'] * 143 + [summary.replace('142', '143')])
        self.assertEqual(CI.require_http_suite(expanded), 143)
        undersized = '\n'.join(['PASS fixture check'] * 141 + [summary.replace('142', '141')])
        for incomplete in [summary, output.replace('PASS ', 'SKIP ', 1), output + '\nUnexpected error', output.replace(summary, summary.replace('142', '143')), undersized]:
            with self.subTest(output=incomplete[-200:]), self.assertRaises(RuntimeError):
                CI.require_http_suite(incomplete)

    @unittest.skipUnless(shutil.which('php'), 'Bootstrap completion checks require the pinned PHP builder')
    def test_zero_exit_html_cannot_complete_bootstrap(self):
        with self.assertRaisesRegex(RuntimeError, 'did not complete'):
            CI.run_php_bootstrap('echo "<h1>Database Error</h1>"; exit(0);', 'negative-fixture')

    @unittest.skipUnless(shutil.which('php'), 'Bootstrap completion checks require the pinned PHP builder')
    def test_bootstrap_marker_and_disposable_hostname_port(self):
        output = CI.run_php_bootstrap("echo json_encode([$_SERVER['HTTP_HOST'],$_SERVER['REQUEST_URI']]);", 'positive-fixture', host='127.0.0.1:8082')
        self.assertIn('["127.0.0.1:8082","\\/"]', output)
        self.assertTrue(output.rstrip().endswith('__WP_CLAMAV_CI_COMPLETE_positive-fixture__'))
        with self.assertRaises(ValueError):
            CI.run_php_bootstrap('echo "unexpected";', 'bad-fixture', host='production.example.com')


if __name__ == '__main__':
    unittest.main()
