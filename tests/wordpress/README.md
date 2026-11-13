# WordPress integration checks

This suite uses a disposable WordPress **7.1.4** installation with Forminator
**1.58.4**, PHP **8.2** and MariaDB **11.4** in the isolated `compact-dev` fixture.
The backend returns explicit test results. Passing these tests demonstrates upload,
storage and notification integration; it does not establish malware detection
quality, subset applicability or SiteGround qualification.

## Pinned sources

Use the official archives when preparing the disposable installation:

| Archive | SHA-256 |
|---|---|
| [WordPress 7.1.4](https://wordpress.org/wordpress-7.1.4.tar.gz) | `881e53a4681ff2cd911651b11cccdede5ae477ddf0765af5d6a1a8b219a1d835` |
| [Forminator 1.58.4](https://downloads.wordpress.org/plugin/forminator.1.58.4.zip) | `528b16be3f35b2f33ddccc76ee140a6de4b3f2428cc4a151fcca9ff81cb110a3` |

The runner does not download WordPress, create a database or install a site. Prepare
the fixture separately, then use the same runner for each integration check.

## Fixture prerequisites

- An installed disposable site at `/workspace/wp-integration-site`, connected to
  the fixture MariaDB server, with `home` and `siteurl` both set to
  `http://127.0.0.1:8081`.
- `define('WP_CLAMAV_INTEGRATION_TEST', true);` in that site's `wp-config.php`.
- An `integration-admin` administrator account with user ID `1`.
- Forminator 1.58.4 in `wp-content/plugins/forminator`, and
  `wp-content/plugins/wp-clamav` linked to `/workspace/wp-clamav`.
- PHP 8.2 or newer with the database and HTTP support used by the fixture, Python 3,
  `curl`, `realpath`, `flock`, and a WP-CLI PHAR.

These paths and the loopback URL are fixed deliberately: `setup.php`, the router
and the fixtures are not a production installer. The fixture mu-plugin loads only
when the test constant is true and the site URL contains `127.0.0.1:8081`. Its
test-only endpoints expose fixture state, and its mail filter captures messages
instead of sending real email. Keep them inside the isolated test container.

## Run the suite

From the host, enter the prepared container with:

```sh
docker exec -it compact-dev bash
```

Then run:

```sh
cd /workspace/wp-clamav
bash tests/wordpress/run.sh
```

The default WP-CLI path is `/host-tmp/wp-clamav-test-wp-cli.phar`. To use another
PHAR already available inside the container:

```sh
WPCLI_PHAR=/absolute/container/path/wp-cli.phar bash tests/wordpress/run.sh
```

The runner checks the test marker, fixed paths, URLs and version pins before
copying the fixture mu-plugin, activating the two plugins and running `setup.php`.
Setup creates three fresh Forminator forms, selects the fixture backend and storage
budgets, and writes test application credentials to
`/workspace/wp-integration-fixture.json`. These credentials are disposable; do not
copy that file to a production deployment or publish its contents.

If no server is listening, the runner starts PHP's built-in server on
`127.0.0.1:8081` with `router.php` and stops that child when the suite exits. It
reuses an existing server without stopping it. Startup failures report the path
to the retained server log in the container's temporary directory. An authenticated
fixture-state probe checks that the responding server has the expected routes.

The commands run in sequence and fail on the first unsuccessful command:

```sh
python3 tests/wordpress/integration_http.py
WP_CLAMAV_WORDPRESS_DIR=/workspace/wp-integration-site \
  php tests/notifications-database.php
WP_CLAMAV_WORDPRESS_DIR=/workspace/wp-integration-site \
  WP_CLAMAV_HTTP_BASE=http://127.0.0.1:8081 \
  php tests/notifications-wordpress-http.php
WP_CLAMAV_WORDPRESS_DIR=/workspace/wp-integration-site \
  php tests/plugin-updates-wordpress.php
```

The upload driver currently performs **142 real HTTP checks** across WordPress
REST multipart/raw-body uploads, sideloads, `wp_upload_bits`, custom upload
actions, and Forminator single, multiple-at-submission and AJAX uploads. It checks
canonical narrative retention and that protected Forminator originals do not
appear in public uploads or native narrative email attachments. Actual administrator
login/download checks cover current event/hash authorization and audit, completed
held/detected review, failed/incomplete denial, tampering, CSRF and role checks.
Notification tests
exercise real `wpdb` persistence plus WordPress HTTP, nonce and cookie behavior.
Their temporary notification tables and route files are removed by their own test
cleanup.

The plugin-update test exercises WordPress's real update and download filters with
a locally signed ZIP and mocked GitHub responses. It does not install a plugin or
download executable releases from the network.

With Chromium and the development-only Playwright package available,
`node tests/wordpress/browser.cjs` checks all three rendered Forminator upload
modes: an otherwise valid narrative is accepted and durably retained when its
required attachment is missing. The fixture URL must be reachable from the
browser's process. Neither tool is a production PHP dependency.

## Authentic local scanner check

`tests/local-native.php` uses the real PHP `LocalBackend`, native executable,
official signed database generation, private store and notification manager. It
skips unless all five fixture locations are explicitly supplied:

```sh
WP_CLAMAV_NATIVE_BINARY=/absolute/package/bin/compact-scan \
WP_CLAMAV_NATIVE_STATE=/absolute/private/native-state \
WP_CLAMAV_NATIVE_GENERATION=/absolute/private/native-state/generations/gen-HASH \
WP_CLAMAV_NATIVE_CERTS=/absolute/package/certs \
WP_CLAMAV_NATIVE_INPUTS=/absolute/fixture-inputs \
  php tests/local-native.php
```

The input directory contains `clean.pdf`, `eicar.pdf` and `pe.pdf`. The last file
must exercise the reduced profile's excluded PE consumer. The test explicitly
allocates 640 MiB native address space and rolling CPU test allowances; these are
not SiteGround account defaults. Historical signed database fixtures can set
`WP_CLAMAV_NATIVE_TEST_MAX_AGE_SECONDS=2592000` explicitly (30 days, test-only).
Without that override the test uses the normal two-day freshness policy. Its
temporary private originals and process fixtures are removed when the test ends;
the supplied database generation is never edited.

`CI=true WP_CLAMAV_NATIVE_FULL_TEST=1` also checks the full profile with an
explicit 2 GiB address-space fixture. This larger allocation is restricted to that
opt-in CI check and is never a GrowBig runtime default.

`WP_CLAMAV_MULTISITE_WORDPRESS_DIR=/workspace/wp-multisite-integration-site php
tests/application-wordpress.php` exercises the separately installed disposable
multisite fixture: blog namespace isolation, shared native/CPU coordination,
per-site storage shares, interrupted-scan recovery and durable event enqueue.

Notification recovery pages canonical event records and durably acknowledges
successful enqueue. The current directory cursor still walks preceding event
metadata to reach later pages; page size does not provide constant-time seeks as
history grows. It does not read original file contents. Large retained histories
need a dedicated event index before constant-work recovery can be claimed.

The runner leaves existing forms, captured mail, private originals and submission
records intact. Each HTTP run uses distinct markers and setup creates fresh forms;
the fixed storage budget and state-list bound still make this a finite disposable
fixture. Recreate it when accumulated test data reaches those bounds. Do not run
it concurrently with manual requests or another suite using the same site. Its
lock serializes this runner only.
