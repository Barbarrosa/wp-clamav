<p><a href="../../README.md">WP ClamAV</a> / <a href="../../docs/README.md">Documentation</a> / WordPress fixtures</p>

<a name="wordpress-integration-checks"></a>

# Test the WordPress integration

Use these fixtures to exercise real WordPress and Forminator hooks, private
storage, scan dispatch, notifications and update filters. The upload tests use a
**fixture scanner backend**; [the native check](#test-the-real-local-scanner)
separately exercises the actual ClamAV executable and authenticated database.

> **Disposable environments only.** The fixture exposes test-only routes, captures
> mail and changes plugin settings. Its fixed paths, loopback URLs and test marker
> are deliberate safeguards. Never copy its mu-plugin, credentials or private
> state into a production site.

## Choose a runner

| Starting point | Runner | What it does |
| --- | --- | --- |
| A fresh isolated environment with PHP, Python and a provisioned MariaDB server | [`tools/wordpress_ci.py`](../../tools/wordpress_ci.py) | Downloads pinned archives, installs disposable sites, runs HTTP and PHP suites, and saves logs. Refuses existing site directories. |
| An already installed, marked disposable single-site fixture | [`run.sh`](run.sh) | Validates the fixture, refreshes its test configuration and runs HTTP, notification and plugin-update checks. Does not download WordPress, create a database or install a site. |

<a name="pinned-sources"></a>

The pinned test matrix is WordPress **7.1.4**, Forminator **1.58.4**, PHP **8.2**
and MariaDB **11.4**. See [the version, download URL and SHA-256
manifest](../../tools/wordpress-fixtures.json) for the authoritative archive
identities. Downloads come from WordPress.org and its plugin distribution service.
The [qualification workflow](../../.github/workflows/compact-plugin.yml) pins the
builder and MariaDB images as well.

**On this page:** [Fresh environment](#start-with-a-fresh-environment) ·
[Prepared fixture](#reuse-a-prepared-fixture) · [Coverage](#understand-what-passed) ·
[Native scanner](#test-the-real-local-scanner) · [Multisite](#test-multisite) ·
[Repeat runs](#repeat-runs-and-retained-data)

## Start with a fresh environment

Use the [qualification workflow](../../.github/workflows/compact-plugin.yml) as
the complete environment recipe. The PHP/Python tooling comes from
[`tools/release/Dockerfile`](../../tools/release/Dockerfile); the runner itself
does not start MariaDB or provision databases.

Before invoking it, provide:

- A writable disposable `/workspace` and access to the pinned archive downloads.
- A MariaDB database and user with permission to install WordPress tables.
- A second database named `wp_clamav_multisite_test` when using `--multisite`.
- Database connection settings through `WP_CI_DB_HOST`, `WP_CI_DB_NAME`,
  `WP_CI_DB_USER` and `WP_CI_DB_PASSWORD`. Use disposable credentials.

Inside that isolated environment:

```sh
python3 tools/wordpress_ci.py \
  --source /workspace/wp-clamav \
  --output /work/logs \
  --multisite
```

Omit `--multisite` to run only the single-site suites. An optional
`WP_CI_FIXTURE_CACHE` directory can supply the original archives; cached bytes
still have to match the pinned SHA-256 values.

The runner creates `/workspace/wp-integration-site` and, when requested,
`/workspace/wp-multisite-integration-site`. It installs the test marker, plugins
and fixture configuration, starts its own loopback server, then stops that
server when finished. It saves individual suite logs under `--output` and checks
that each suite actually reports completion: a zero process exit, HTML error or
skipped PHP suite cannot pass qualification.

<a name="run-the-suite"></a>

## Reuse a prepared fixture

<a name="fixture-prerequisites"></a>

### Required identity and tools

The prepared single-site fixture must have all of the following:

| Requirement | Exact fixture value |
| --- | --- |
| WordPress directory | `/workspace/wp-integration-site` |
| `home` and `siteurl` | `http://127.0.0.1:8081` |
| Test marker in `wp-config.php` | `define('WP_CLAMAV_INTEGRATION_TEST', true);` |
| Administrator | `integration-admin`, user ID `1` |
| Forminator | Version `1.58.4`, in `wp-content/plugins/forminator` |
| Plugin checkout | `wp-content/plugins/wp-clamav` linked to `/workspace/wp-clamav` |

Install PHP 8.2 or newer with the database and HTTP support the fixture uses,
Python 3, `curl`, `realpath`, `flock` and a WP-CLI PHAR. The default PHAR location is
`/host-tmp/wp-clamav-test-wp-cli.phar` inside the prepared container.

### Run the prepared-site checks

If your prepared container is named `compact-dev`, enter it with:

```sh
docker exec -it compact-dev bash
```

Then run from the checkout:

```sh
cd /workspace/wp-clamav
bash tests/wordpress/run.sh
```

For a WP-CLI PHAR at a different container path:

```sh
WPCLI_PHAR=/absolute/container/path/wp-cli.phar \
  bash tests/wordpress/run.sh
```

The script validates paths, URLs, versions, the administrator and test marker
before copying the fixture mu-plugin, activating Forminator/WP ClamAV and running
[`setup.php`](setup.php). Setup creates fresh forms for single, submission-time,
AJAX, multiple-field and blocked-type cases. It selects the fixture backend and
explicit test budgets, then writes disposable application credentials to
`/workspace/wp-integration-fixture.json`. Keep that file private.

If port `8081` is unused, the runner starts PHP's built-in server with
[`router.php`](router.php) and stops its own child on exit. It reuses an existing
server without stopping it. A fixture-state probe verifies the expected routes;
startup failures identify the retained temporary server log.

The prepared runner executes these suites in order, stopping on failure:

1. [`integration_http.py`](integration_http.py): real upload and lifecycle requests.
2. [`notifications-database.php`](../notifications-database.php): real `wpdb` persistence.
3. [`notifications-wordpress-http.php`](../notifications-wordpress-http.php): real nonce, cookie and download behavior.
4. [`plugin-updates-wordpress.php`](../plugin-updates-wordpress.php): WordPress update/download filters.

The fresh CI runner also runs the standalone dispatch suite. To run it against a
prepared fixture:

```sh
WP_CLAMAV_WORDPRESS_DIR=/workspace/wp-integration-site \
  php tests/dispatch-wordpress.php
```

## Understand what passed

| Suite | Behavior it verifies |
| --- | --- |
| Upload HTTP lifecycle | REST multipart/raw uploads, sideloads, `wp_upload_bits`, custom upload actions, and Forminator single, multiple and AJAX uploads. |
| Forminator admission | Final submission binding, real-clock flood pacing, count/type/framing holds, draft/abandoned/spam/preview exclusion, token replay and narrative preservation. |
| Privacy and administrator review | Originals stay out of public uploads and Forminator narrative attachments; current event/hash checks, auditing, CSRF and role enforcement protect review. |
| Dispatch | Coalesced cron wake-ups, bounded retries, resource deferrals, legacy intent conversion and stale queued-request retirement. |
| Notifications | SQL persistence, email verification and cookies, completed held/detected review, failed/incomplete denial, tampering and revocation. |
| Plugin update filters | A locally signed ZIP and mocked GitHub responses exercise WordPress hooks without installing a plugin or downloading executable releases. |

The suite prints its actual check count at completion; inspect that summary and
the saved logs instead of relying on a count in documentation. Passing fixture
backend tests does not establish malware detection quality, reduced-profile
applicability or actual SiteGround qualification. Mail is captured locally; no
real email is sent.

Functional cases use a trusted fixture-only virtual resource clock to move
between admitted jobs without sleeping. Flood/admission cases also use the real
clock. Neither mode bypasses the shared resource gate, and production settings
cannot select the fixture clock.

### Optional browser check

With Chromium and the development-only Playwright package available:

```sh
node tests/wordpress/browser.cjs
```

The browser must reach `http://127.0.0.1:8081`. This check uses the three rendered
single, submission and AJAX forms to confirm that a valid narrative remains
accepted and durable when its required attachment is missing. Chromium and
Playwright are not production PHP dependencies.

<a name="authentic-local-scanner-check"></a>

## Test the real local scanner

[`tests/local-native.php`](../local-native.php) uses the real PHP `LocalBackend`,
native executable, official signed database generation, private store and
notification manager. It skips unless all five paths are explicitly supplied:

```sh
WP_CLAMAV_NATIVE_BINARY=/absolute/package/bin/compact-scan \
WP_CLAMAV_NATIVE_STATE=/absolute/private/native-state \
WP_CLAMAV_NATIVE_GENERATION=/absolute/private/native-state/generations/gen-HASH \
WP_CLAMAV_NATIVE_CERTS=/absolute/package/certs \
WP_CLAMAV_NATIVE_INPUTS=/absolute/fixture-inputs \
  php tests/local-native.php
```

Provide `clean.pdf`, `eicar.pdf` and `pe.pdf` in the input directory; `pe.pdf` must
exercise the reduced profile's excluded PE consumer. The test uses an explicit
640 MiB address-space allocation, confirmed test process/account memory and
rolling CPU allowances. Those values are fixture inputs, never GrowBig defaults.
The trusted ledger clock advances between functional cases; real process CPU,
memory and wall limits still apply.

For a historical signed database fixture, explicitly set
`WP_CLAMAV_NATIVE_TEST_MAX_AGE_SECONDS=2592000` to allow up to 30 days. The normal
freshness policy remains two days without that test-only override. Temporary
originals and process fixtures are removed on exit; the supplied database
generation is not edited.

Adding `CI=true WP_CLAMAV_NATIVE_FULL_TEST=1` enables the full-profile case under
an explicit 2 GiB address-space allocation. That larger envelope is restricted to
this opt-in CI check and is not a hosting default.

## Test multisite

Use the separate, marked multisite fixture:

```sh
WP_CLAMAV_MULTISITE_WORDPRESS_DIR=/workspace/wp-multisite-integration-site \
  php tests/application-wordpress.php
```

It verifies blog namespace isolation, shared native/CPU/mail coordination,
per-site storage shares, interrupted-scan recovery and durable event enqueue.
The fresh runner creates this fixture when invoked with `--multisite`.

## Repeat runs and retained data

Prepared-site runs keep existing forms, captured mail, private originals,
receipts, events, audits and submissions. Each HTTP run uses distinct markers and
setup creates fresh forms. Setup resets only the disposable fixture's operational
CPU ledger and virtual-clock state so a previous simulated future cannot affect
the next real-clock test; production never performs this reset.

The fixed storage budget and state-list bound make this a finite test fixture.
Recreate the isolated environment when accumulated test data reaches those bounds.
Do not run another suite or manual requests against the same site concurrently:
`run.sh` serializes its own invocations, not every consumer of the fixture.

Notification recovery still walks preceding canonical event metadata to reach
later cursor pages. It does not read original bytes, but page size does not imply
constant-time seeking through indefinite history. A dedicated event index is
needed before constant-work recovery can be claimed.

---

[Development guide](../../docs/development.md) ·
[Scan dispatch](../../docs/scan-dispatch.md) ·
[Release process](../../docs/releases.md)
