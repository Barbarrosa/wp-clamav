#!/usr/bin/env python3
"""Run the real upload-hook fixture on a disposable WordPress/MariaDB site.
SPDX-License-Identifier: GPL-2.0-or-later
"""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import subprocess
import tarfile
import tempfile
import time
import urllib.error
import urllib.request
import zipfile


def relative(name):
    p = PurePosixPath(name)
    if p.is_absolute() or '..' in p.parts:
        raise ValueError('Unsafe fixture archive path')
    return p


def download(spec, destination):
    cache = os.environ.get('WP_CI_FIXTURE_CACHE')
    cached = Path(cache) / spec['url'].rsplit('/', 1)[-1] if cache else None
    if cached and cached.is_file():
        shutil.copyfile(cached, destination)
    else:
        with urllib.request.urlopen(spec['url'], timeout=120) as response, destination.open('wb') as output:
            shutil.copyfileobj(response, output)
    if hashlib.sha256(destination.read_bytes()).hexdigest() != spec['sha256']:
        raise ValueError('Pinned WordPress fixture digest changed')


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--source', type=Path, default=Path(__file__).resolve().parents[1])
    p.add_argument('--output', type=Path, required=True)
    p.add_argument('--multisite', action='store_true')
    a = p.parse_args()
    a.output.mkdir(parents=True, exist_ok=True)
    source = a.source.resolve()
    workspace = Path('/workspace')
    workspace.mkdir(exist_ok=True)
    alias = workspace / 'wp-clamav'
    if alias.resolve() != source:
        if alias.exists():
            raise ValueError('Fixture alias already belongs to another checkout')
        alias.symlink_to(source, target_is_directory=True)
    site = workspace / 'wp-integration-site'
    if site.exists():
        raise ValueError('Refusing to reuse an existing WordPress site')
    specs = json.loads((source / 'tools/wordpress-fixtures.json').read_text())
    with tempfile.TemporaryDirectory(prefix='wp-clamav-ci-') as temporary:
        tmp = Path(temporary)
        wp, fm = tmp / 'wordpress.tar.gz', tmp / 'forminator.zip'
        download(specs['wordpress'], wp)
        download(specs['forminator'], fm)
        with tarfile.open(wp, 'r:gz') as archive:
            for member in archive:
                relative(member.name)
                if not (member.isfile() or member.isdir()):
                    raise ValueError('Unexpected link in WordPress fixture')
                archive.extract(member, tmp, numeric_owner=False)
        if a.multisite:
            multi = workspace / 'wp-multisite-integration-site'
            if multi.exists():
                raise ValueError('Refusing to reuse an existing multisite fixture')
            shutil.copytree(tmp / 'wordpress', multi)
        shutil.move(str(tmp / 'wordpress'), site)
        plugins = site / 'wp-content/plugins'
        with zipfile.ZipFile(fm) as archive:
            for member in archive.infolist():
                relative(member.filename)
                if (member.external_attr >> 16) & 0o170000 == 0o120000:
                    raise ValueError('Unexpected link in Forminator fixture')
                archive.extract(member, plugins)
    (plugins / 'wp-clamav').symlink_to(source, target_is_directory=True)
    mu = site / 'wp-content/mu-plugins'
    mu.mkdir(exist_ok=True)
    shutil.copyfile(source / 'tests/wordpress/fixture-mu.php', mu / 'wp-clamav-ci.php')
    # Credentials and isolated database are test-only, supplied by the CI service.
    values = {'DB_NAME': os.environ.get('WP_CI_DB_NAME', 'wpclamav'),
              'DB_USER': os.environ.get('WP_CI_DB_USER', 'wpclamav'),
              'DB_PASSWORD': os.environ.get('WP_CI_DB_PASSWORD', 'integration-only-password'),
              'DB_HOST': os.environ.get('WP_CI_DB_HOST', 'mariadb')}
    config = '<?php\n' + ''.join(f'define({json.dumps(k)}, {json.dumps(v)});\n' for k, v in values.items())
    config += '''define('WP_CLAMAV_INTEGRATION_TEST', true);
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_HOME', 'http://127.0.0.1:8081');
define('WP_SITEURL', 'http://127.0.0.1:8081');
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
$table_prefix = 'wp_';
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once ABSPATH . 'wp-settings.php';
'''
    (site / 'wp-config.php').write_text(config)
    setup = """$_SERVER['HTTP_HOST']='127.0.0.1:8081';
define('WP_INSTALLING', true);
require '/workspace/wp-integration-site/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
add_filter('pre_wp_mail', '__return_true');
wp_install('WP ClamAV CI', 'integration-admin', 'integration@example.invalid', false, '', 'integration-only-password');
foreach (['forminator/forminator.php','wp-clamav/wp-clamav.php'] as $plugin) {
    $result=activate_plugin($plugin); if(is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
}
"""
    subprocess.run(['php', '-r', setup], check=True, timeout=180)
    # Plugins activated after WordPress bootstrap need a fresh request before
    # invoking their APIs; this matches normal Forminator initialization.
    subprocess.run(['php', '-r', "$_SERVER['HTTP_HOST']='127.0.0.1:8081'; require '/workspace/wp-integration-site/wp-load.php'; require '/workspace/wp-clamav/tests/wordpress/setup.php';"],
                   check=True, timeout=180)
    with (a.output / 'wordpress-server.log').open('w') as log:
        server = subprocess.Popen(['php', '-S', '127.0.0.1:8081', '-t', str(site),
                                   str(source / 'tests/wordpress/router.php')], stdout=log, stderr=log)
        try:
            for _ in range(60):
                if server.poll() is not None:
                    raise RuntimeError('WordPress fixture server stopped')
                try:
                    urllib.request.urlopen('http://127.0.0.1:8081/', timeout=2).close()
                    break
                except (OSError, urllib.error.URLError):
                    time.sleep(0.5)
            else:
                raise RuntimeError('WordPress fixture server was not ready')
            with (a.output / 'wordpress-http.log').open('w') as results:
                subprocess.run(['python3', str(source / 'tests/wordpress/integration_http.py')],
                                check=True, stdout=results, stderr=subprocess.STDOUT)
            test_env = os.environ.copy()
            test_env.update(WP_CLAMAV_WORDPRESS_DIR=str(site), WP_CLAMAV_HTTP_BASE='http://127.0.0.1:8081')
            for name in ('plugin-updates-wordpress.php', 'notifications-database.php', 'notifications-wordpress-http.php'):
                with (a.output / (name + '.log')).open('w') as results:
                    subprocess.run(['php', str(source / 'tests' / name)], env=test_env,
                                   check=True, timeout=180, stdout=results, stderr=subprocess.STDOUT)
            if a.multisite:
                (multi / 'wp-content/plugins/wp-clamav').symlink_to(source, target_is_directory=True)
                multi_values = dict(values, DB_NAME='wp_clamav_multisite_test')
                multi_config = '<?php\n' + ''.join(f'define({json.dumps(k)}, {json.dumps(v)});\n' for k, v in multi_values.items())
                settings = config[config.index("define('WP_CLAMAV_INTEGRATION_TEST'"):].replace(':8081', ':8082')
                (multi / 'wp-config.php').write_text(multi_config + "define('WP_ALLOW_MULTISITE', true);\n" + settings)
                populate = """$_SERVER['HTTP_HOST']='127.0.0.1:8082';define('WP_INSTALLING',true);
require '/workspace/wp-multisite-integration-site/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail','__return_true');
wp_install('Multisite fixture','integration-admin','integration@example.invalid',false,'','integration-only-password');
install_network();
$result=populate_network(1,'127.0.0.1:8082','integration@example.invalid','Multisite fixture','/',false);
if(is_wp_error($result)){throw new RuntimeException($result->get_error_message());}
"""
                subprocess.run(['php', '-r', populate], check=True, timeout=180)
                constants = """define('MULTISITE',true);
define('SUBDOMAIN_INSTALL',false);
define('DOMAIN_CURRENT_SITE','127.0.0.1:8082');
define('PATH_CURRENT_SITE','/');
define('SITE_ID_CURRENT_SITE',1);
define('BLOG_ID_CURRENT_SITE',1);
"""
                path = multi / 'wp-config.php'
                path.write_text(path.read_text().replace("define('WP_ALLOW_MULTISITE', true);", "define('WP_ALLOW_MULTISITE', true);\n" + constants))
                activate = """$_SERVER['HTTP_HOST']='127.0.0.1:8082';require '/workspace/wp-multisite-integration-site/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php'; add_filter('pre_wp_mail','__return_true');
$result=activate_plugin('wp-clamav/wp-clamav.php','',true);
if(is_wp_error($result)){throw new RuntimeException($result->get_error_message());}
"""
                subprocess.run(['php', '-r', activate], check=True, timeout=180)
                test_env['WP_CLAMAV_MULTISITE_WORDPRESS_DIR'] = str(multi)
                with (a.output / 'application-multisite.log').open('w') as results:
                    subprocess.run(['php', str(source / 'tests/application-wordpress.php')], env=test_env,
                                   check=True, timeout=180, stdout=results, stderr=subprocess.STDOUT)
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
    if (site / 'wp-content/debug.log').is_file():
        shutil.copyfile(site / 'wp-content/debug.log', a.output / 'wordpress-debug.log')


if __name__ == '__main__':
    main()
