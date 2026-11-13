#!/usr/bin/env python3
"""Build a deterministic WordPress ZIP and pending release metadata.
SPDX-License-Identifier: GPL-2.0-or-later
"""
import argparse
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import tarfile
import time
import zipfile


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def write(path, value):
    Path(path).write_text(json.dumps(value, sort_keys=True, indent=2) + '\n')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--qualification', type=Path, required=True)
    parser.add_argument('--version', default='0.1.0')
    parser.add_argument('--repository', required=True)
    parser.add_argument('--integration-sha', required=True)
    parser.add_argument('--sequence', type=int, required=True)
    parser.add_argument('--expires-at', type=int, required=True)
    parser.add_argument('--channel', choices=('preview', 'stable'), default='preview')
    args = parser.parse_args()
    if args.output.exists() or not re.fullmatch(r'\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?', args.version):
        parser.error('A new output directory and semantic version are required')
    if args.sequence < 1 or args.expires_at <= int(time.time()) or not re.fullmatch('[0-9a-f]{40}', args.integration_sha):
        parser.error('Invalid release identity, sequence or expiration')
    report = json.loads(args.qualification.read_text())
    if report.get('passed') is not True or report.get('integration_sha') != args.integration_sha:
        parser.error('A passing qualification report for this commit is required')
    if args.channel == 'stable' and report.get('stable_release_qualified') is not True:
        parser.error('Stable qualification remains incomplete')
    source = args.source.resolve()
    if args.output.resolve().is_relative_to(source):
        parser.error('Release output must be outside the plugin source tree')
    main_php = (source / 'wp-clamav.php').read_text()
    if not re.search(r'\* Version:\s*' + re.escape(args.version) + r'\s*\n', main_php):
        parser.error('Plugin header and requested version differ')
    files = []
    for folder in ('src', 'assets', 'docs'):
        files.extend(p for p in (source / folder).rglob('*') if p.is_file())
    for name in ('wp-clamav.php', 'uninstall.php', 'README.md', 'readme.txt', 'LICENSE', 'COPYING.txt'):
        if (source / name).is_file():
            files.append(source / name)
    if not any(p.name in ('LICENSE', 'COPYING.txt') for p in files):
        parser.error('Plugin license text is required')
    if any(p.is_symlink() for p in files):
        parser.error('Plugin distribution may not contain links')
    args.output.mkdir(parents=True)
    zip_path = args.output / ('wp-clamav-' + args.version + '.zip')
    with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(set(files)):
            rel = path.relative_to(source).as_posix()
            info = zipfile.ZipInfo('wp-clamav/' + rel, (1980, 1, 1, 0, 0, 0))
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED)
    tag = 'v' + args.version
    sha = digest(zip_path)
    write(args.output / 'manifest.json', {
        'protocol': 1, 'sequence': args.sequence, 'expires_at': args.expires_at,
        'channel': args.channel, 'version': args.version, 'platform': 'wordpress-php-8.2+',
        'repository': args.repository, 'integration_sha': args.integration_sha,
        'tag': tag, 'experimental': args.channel != 'stable',
        'files': [{'path': zip_path.name, 'sha256': sha, 'size': zip_path.stat().st_size,
                   'mode': '0400', 'url': 'https://github.com/' + args.repository + '/releases/download/' + tag + '/' + zip_path.name}]})
    write(args.output / 'qualification.json', report)
    write(args.output / 'provenance.json', {'integration_sha': args.integration_sha,
        'source_complete': True, 'github_run_id': os.environ.get('GITHUB_RUN_ID'), 'prepared_unsigned': True})
    write(args.output / 'sbom.spdx.json', {
        'spdxVersion': 'SPDX-2.3', 'dataLicense': 'CC0-1.0', 'SPDXID': 'SPDXRef-DOCUMENT',
        'name': 'wp-clamav-' + args.version,
        'documentNamespace': 'https://github.com/' + args.repository + '/releases/' + tag,
        'creationInfo': {'creators': ['Tool: wp-clamav-package'],
                         'created': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())},
        'packages': [{'SPDXID': 'SPDXRef-WPClamAV', 'name': 'WP ClamAV',
                      'versionInfo': args.version, 'downloadLocation': 'https://github.com/' + args.repository,
                      'licenseDeclared': 'GPL-2.0-or-later', 'filesAnalyzed': False}],
        'relationships': [{'spdxElementId': 'SPDXRef-DOCUMENT', 'relationshipType': 'DESCRIBES',
                           'relatedSpdxElement': 'SPDXRef-WPClamAV'}]})
    # The plugin ZIP is complete interpreted source; retain tools/tests in the
    # separate corresponding-source archive as well.
    with (args.output / ('wp-clamav-' + args.version + '-source.tar.gz')).open('wb') as raw:
        with gzip.GzipFile(fileobj=raw, mode='wb', mtime=0) as compressed:
            with tarfile.open(fileobj=compressed, mode='w') as archive:
                for path in sorted(source.rglob('*')):
                    rel = path.relative_to(source)
                    if any(part in {'.git', '__pycache__', '.pytest_cache'} for part in rel.parts):
                        continue
                    if path.is_symlink():
                        raise ValueError('Source links are forbidden')
                    info = archive.gettarinfo(str(path), str(Path('wp-clamav') / rel))
                    info.uid = info.gid = info.mtime = 0
                    info.uname = info.gname = ''
                    if info.isfile():
                        with path.open('rb') as stream:
                            archive.addfile(info, stream)
                    else:
                        archive.addfile(info)
    source_archive = args.output / ('wp-clamav-' + args.version + '-source.tar.gz')
    manifest = json.loads((args.output / 'manifest.json').read_text())
    manifest['source_archives'] = [{'name': source_archive.name, 'size': source_archive.stat().st_size,
        'sha256': digest(source_archive), 'url': 'https://github.com/' + args.repository + '/releases/download/' + tag + '/' + source_archive.name}]
    for name in ('qualification.json', 'provenance.json', 'sbom.spdx.json'):
        manifest[name.replace('.', '_') + '_sha256'] = digest(args.output / name)
    write(args.output / 'manifest.json', manifest)
    index = [{'path': p.name, 'size': p.stat().st_size, 'sha256': digest(p)}
             for p in sorted(args.output.iterdir()) if p.is_file()]
    write(args.output / 'bundle-index.json', index)
    with (args.output.parent / ('wp-clamav-' + args.version + '-candidate.tar.gz')).open('wb') as raw:
        with gzip.GzipFile(fileobj=raw, mode='wb', mtime=0) as compressed:
            with tarfile.open(fileobj=compressed, mode='w') as archive:
                for path in sorted(args.output.iterdir()):
                    info = archive.gettarinfo(str(path), 'candidate/' + path.name)
                    info.uid = info.gid = info.mtime = 0
                    info.uname = info.gname = ''
                    with path.open('rb') as stream:
                        archive.addfile(info, stream)
    print(json.dumps({'status': 'pending-signature', 'zip_sha256': sha}))


if __name__ == '__main__':
    main()
