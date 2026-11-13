#!/usr/bin/env python3
"""Lint every PHP source file, failing on an empty tree or any PHP process error.
SPDX-License-Identifier: GPL-2.0-or-later
"""
import argparse
from pathlib import Path
import shutil
import subprocess
import sys


def lint(source, php=None):
    source = Path(source).resolve()
    php = php or shutil.which('php')
    if not php:
        raise RuntimeError('PHP is required for the syntax gate.')
    if not source.is_dir():
        raise RuntimeError('The PHP source directory does not exist.')
    files = sorted(path for path in source.rglob('*.php') if path.is_file())
    if not files:
        raise RuntimeError('No PHP files were selected; the syntax gate did not run.')
    for path in files:
        if path.is_symlink():
            raise RuntimeError('PHP source links are forbidden: ' + str(path))
        subprocess.run([str(php), '-l', str(path)], check=True, stdin=subprocess.DEVNULL)
    print('PHP syntax gate passed: ' + str(len(files)) + ' files.', flush=True)
    return len(files)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, required=True)
    arguments = parser.parse_args()
    try:
        lint(arguments.source)
    except (OSError, RuntimeError, subprocess.SubprocessError) as error:
        print('PHP syntax gate failed: ' + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
