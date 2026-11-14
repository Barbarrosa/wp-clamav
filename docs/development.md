<p><a href="../README.md">WP ClamAV</a> / <a href="README.md">Documentation</a></p>

# Develop and verify changes

Start with the isolated PHP contracts. Add the real WordPress or native test layer
when a change crosses that boundary. The [architecture guide](architecture.md)
maps the source before you edit it.

**On this page:** [Local tools](#local-tools) · [Fast checks](#run-the-fast-checks) ·
[Test layers](#choose-the-right-test-layer) · [Boundaries](#change-a-boundary-deliberately) ·
[CI](#understand-the-ci-gates) · [Documentation](#maintain-the-documentation)

## Local tools

| Work | Tools |
| --- | --- |
| Plugin contracts and syntax | PHP 8.2+; Linux provides the full process/locking test environment used by CI. |
| Packaging and orchestration checks | Python 3; tooling tests use the standard library and invoke PHP where needed. |
| WordPress integration | Pinned WordPress/Forminator archives, PHP HTTP/database support, MariaDB, WP-CLI and a disposable environment. |
| Native integration | A matching `compact-scan` executable, authenticated generation, certificates and explicit input fixtures. |
| Documentation | A text editor; GitHub renders Markdown, Mermaid and the local SVGs. No docs build or JavaScript toolchain. |

There is no Composer install or npm build step for the website plugin. Use the
[CI Dockerfile](../tools/release/Dockerfile) and
[workflow](../.github/workflows/compact-plugin.yml) when you need the exact pinned
integration environment. The fixtures' extra tools are development dependencies.

Use a checkout or the complete source archive for developer documentation links
to tests, tools and workflows. Those development files are not installed by the
lightweight website plugin ZIP.

## Run the fast checks

From the repository root:

```sh
php tests/run.php
python3 tools/lint_php.py --source .
python3 -m unittest discover -s tools -p 'test_*.py'
```

The [PHP runner](../tests/run.php) starts standalone contracts in separate PHP
processes. Environment-dependent suites report **skipped** unless their explicit
fixture inputs are supplied. A successful fast run alone is not proof that those
integrations ran. The CI integration runner rejects skipped fixture suites and
requires actual completion summaries.

The [lint gate](../tools/lint_php.py) checks a nonempty PHP tree and propagates
parse failures. The [tooling tests](../tools/) verify deterministic packaging and
that fixture orchestration cannot treat an error page or empty report as success.

## Choose the right test layer

| Change | Start with | Then verify |
| --- | --- | --- |
| Admission, memory or CPU policy | `tests/resource-dispatch.php`, `tests/scan-admission.php`, `tests/resource-ledgers.php` | Relevant real dispatch or native case. |
| Canonical storage or quota accounting | `tests/storage-accounting.php`, `tests/operational-storage.php`, `tests/network-allocations.php` | Multisite application fixture where shared state is affected. |
| Notification eligibility or delivery | `tests/notifications.php` | Real `wpdb` / HTTP suites if SQL, nonces, cookies or WordPress hooks change. |
| Queue scheduling or adapter behavior | `tests/scan-queue.php` | `tests/dispatch-wordpress.php` and the real Forminator HTTP lifecycle. |
| Native subprocess supervision | `tests/scan-admission.php` | `tests/local-native.php` with explicit actual-engine inputs. |
| Plugin/native release client | `tests/code-update-client.php`, `tests/updates.php` | Real plugin update-hook fixture and package verification. |
| README, guides or SVG assets | Local links, fragments, image rendering and license/source notices | Packaging inclusion and normal CI. |

Run an individual standalone PHP suite with `php tests/<file>.php`. See
[WordPress fixture instructions](../tests/wordpress/README.md) for the exact
fresh/prepared runners, native environment variables and multisite setup.

> **Fixture boundary:** test routes, mail capture, credentials and virtual clocks
> belong only in marked disposable sites. HTTP tests use a fixture scanner;
> actual native scans are a separate layer. No fixture allocation is a production
> default, and no fixture run qualifies a GrowBig account.

## Change a boundary deliberately

### Add or update an upload adapter

Preserve the original before a public move, keep narrative independent of scan
completion, and bind every decision to the original hash and size. For a queued
form integration, require a trusted final submission descriptor before granting
automatic work. Client MIME, receipt tokens and client file totals cannot grant
coverage or scan eligibility.

When qualifying a new Forminator version, inspect the vendor lifecycle and upload
hooks, update the adapter deliberately, and exercise final, AJAX, draft,
abandoned, spam and preview paths through real requests. Changing the qualified
version string by itself does not qualify the integration.

### Add a scanner backend

Implement [ScannerBackend](../src/Scanning/ScannerBackend.php) and return the
[validated result protocol](../src/Scanning/ScanResult.php). The trusted
`wp_clamav_backend` filter receives the backend and settings. Use the shared
admission/deadline path; do not create a second process-launch path in an adapter.
Incomplete work cannot be made clean by translating an exit code or HTTP 200.

### Work on persistence or resource policy

[SubmissionStore](../src/Contracts/SubmissionStore.php) describes the persistence
boundary. The current application uses an on-site filesystem store; an external
datastore is not a selectable implementation today. The trusted
`wp_clamav_resource_policy` filter is server-side dependency injection, including
fixture clocks. Request data or stored settings cannot select a test clock or
bypass resource admission.

## Understand the CI gates

[Compact plugin qualification](../.github/workflows/compact-plugin.yml) builds a
pinned environment, runs syntax/contracts/tooling checks, then real WordPress,
Forminator, notification, update and multisite suites. Its default-branch build
packages a pending candidate and creates provenance attestations.

[Protected promotion](../.github/workflows/compact-promote.yml) signs the exact
reviewed candidate rather than rebuilding it. Signing and stable qualification
are separate gates. The [native repository](https://github.com/Barbarrosa/clamav/tree/upstream-optimized/compact-integration)
has its own build and qualification pipeline. Neither workflow deploys a live site.

## Maintain the documentation

- Use the [docs index](README.md) as the navigation hub; preserve existing guide
  paths when reorganizing content so external links continue to work.
- Put installation steps in [getting started](getting-started.md), option names
  in [configuration](configuration.md), and dense policy/API detail in references.
- Distinguish actual defaults, tested targets, observed measurements and provider
  references. Read the source before adding a limit or a compatibility claim.
- Preview narrow and wide layouts. Give informative images text equivalents;
  decorative icons have empty alt text next to their visible labels.
- Keep SVGs static and small. Preserve [visual license/source records](visual-assets.md),
  including notices shipped inside the plugin ZIP.
- Badge states must be factual. Do not add a coverage percentage or certification
  badge without a measured source and an appropriate qualification process.

Next: [Contributing](../CONTRIBUTING.md) · [WordPress fixtures](../tests/wordpress/README.md)
· [Releases](releases.md)
