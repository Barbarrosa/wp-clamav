<p><a href="README.md">WP ClamAV</a> / Contributing</p>

# Contributing

Start with the [development guide](docs/development.md) for the code map, local workflow and test environments. Use the [architecture guide](docs/architecture.md) to understand where uploads become private, scans are admitted and publication is authorized.

## Make a focused change

1. Create a branch from `main` and describe the behavior you intend to change.
2. Keep the change small enough to review alongside its relevant tests and documentation.
3. Run the checks that exercise that behavior. Include the commands and outcomes in the pull request.
4. State remaining limits explicitly, including skipped fixtures or deployment assumptions.

Documentation improvements, clearer diagnostics and smaller runtime dependencies are useful contributions. Keep website execution lightweight; development tooling belongs outside the plugin's runtime path.

## Run the local checks

Use PHP 8.2+ and Python 3. The [development guide](docs/development.md) covers the environment and extensions used by CI.

```sh
python3 tools/lint_php.py --source .
php tests/run.php
python3 -m unittest discover -s tools -p 'test_*.py' -v
git diff --check
```

`tests/run.php` includes opt-in suites that report **skipped** until their explicit native or WordPress fixtures are supplied. A skipped suite is not coverage for that integration. Use the [WordPress test guide](tests/wordpress/README.md) for the disposable fixtures and real HTTP checks.

Add tests for changed behavior and meaningful failure paths. Documentation-only changes should verify links, commands and source references; they do not need unrelated implementation tests.

## Preserve the core contracts

| Contract | Review expectation |
| --- | --- |
| Original identity | Scan and publication decisions remain bound to the preserved original's SHA-256 and length. |
| Private retention | Preserve accepted originals, narrative and immutable scan history. Resource pressure, uninstall and failures never become automatic deletion. |
| Resource admission | Acquire shared admission before native preparation or startup; respect CPU, RAM, deadlines, queue bounds and maintenance capacity. Unknown capacity stays unknown. |
| Publication and sharing | Failed, deferred or incomplete work cannot authorize publication or file-sharing assets. Explicit administrator review remains audited. |
| Trusted updates | Preserve exact signed manifests, pinned origins, file identities, channel separation and atomic native program/database selection. |
| Test isolation | Fixture clocks, fake backends and diagnostic hooks stay in trusted test integration; request data or stored settings cannot enable bypasses. |

The [scan dispatch](docs/scan-dispatch.md), [storage](docs/storage-accounting.md) and [notification](docs/notifications.md) references describe these contracts in detail.

## Write a useful pull request

Lead with the problem and resulting behavior. Include relevant validation and any material compatibility, retention or deployment limits. A small change usually needs a short description and its checks; a cross-component change should explain how the boundaries stay consistent.

For a bug report, provide reproduction steps, expected and actual behavior, component versions, selected backend/profile and relevant reason codes. Remove credentials, original uploads, submission contents and live recipient links from anything posted publicly.

## Documentation and visual assets

Keep instructions task-first and link to the detailed reference instead of duplicating it. Verify command names, paths and settings against the implementation. Distinguish tested versions from minimum requirements and measured allocations from provider references.

Preserve license headers and third-party notices. New visual assets need a recorded source, license and any required attribution; follow the [visual asset record](docs/visual-assets.md). Contributions should remain compatible with the project's [GPL-2.0-or-later terms](LICENSE) and existing per-file licenses.

Release publication uses the separate protected process in [builds and releases](docs/releases.md). A pull request, successful test run or documentation update does not deploy a website or grant access to signing keys.

---

**Continue:** [Development guide](docs/development.md) · [Documentation index](docs/README.md) · [Integration tests](tests/wordpress/README.md)
