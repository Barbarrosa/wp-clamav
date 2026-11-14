<p><a href="../README.md">WP ClamAV</a> / Documentation</p>

# Documentation

Set up private upload scanning, understand its decisions, and work on the plugin
without losing the guarantees around retained data and resource limits.

> **Current scope:** experimental implementation, with a WordPress 7.1.4 /
> Forminator 1.58.4 integration fixture. Actual SiteGround GrowBig qualification
> and signed release promotion remain separate deployment tasks.

## Choose your path

| <img src="assets/icons/book-open.svg" width="28" height="28" alt=""><br>Install | <img src="assets/icons/settings-2.svg" width="28" height="28" alt=""><br>Operate | <img src="assets/icons/code-xml.svg" width="28" height="28" alt=""><br>Develop |
| :--- | :--- | :--- |
| [Get started](getting-started.md) → [Configure](configuration.md) → qualify your staging site. | [Understand a hold](troubleshooting.md) → [Review notifications](notifications.md) → manage retained data. | [Read the architecture](architecture.md) → [Run checks](development.md) → [Contribute](../CONTRIBUTING.md). |

## Installation and operation

| Guide | Use it for |
| --- | --- |
| [Getting started](getting-started.md) | Requirements, private storage, local/remote choice, first configuration and staging acceptance. |
| [Configuration](configuration.md) | Real defaults, settings locations, verification keys and numeric resource allocations. |
| [Troubleshooting](troubleshooting.md) | Deferred scans, native startup, freshness, form eligibility, mail and updates. |
| [Notifications](notifications.md) | Rules, recipients, attachments, email-verified links and delivery semantics. |
| [Storage and retention](storage-accounting.md) | Canonical data, quota baselines, recovery, backups and explicit deletion. |

## Implementation and maintenance

| Guide | Use it for |
| --- | --- |
| [Architecture](architecture.md) | Upload flows, source map, result protocol and extension boundaries. |
| [Development](development.md) | Local tools, test layers, source changes and documentation maintenance. |
| [Scan dispatch](scan-dispatch.md) | Shared admission, durable intent, coalesced wake-ups and bounded retries. |
| [Resource constraints](resource-constraints.md) | CPU/RAM policy, diagnostics provenance, deadlines and complete-message mail capacity. |
| [WordPress fixtures](../tests/wordpress/README.md) | Fresh and prepared environments, HTTP tests, native checks and multisite. |
| [Releases](releases.md) | GitHub Actions, candidate verification, protected signing and immutable tags. |
| [Visual assets](visual-assets.md) | Artwork sources, licenses, static badges and design conventions. |

## Keep these boundaries in mind

- The plugin is disabled until configured and explicitly enabled.
- A clean result needs completed, qualified coverage of the exact original.
  A hold, failure or capacity refusal does not approve publication.
- Accepted originals, narrative and immutable history remain until explicit deletion.
  Expiring a link or deactivating the plugin does not remove them.
- Resource allocations come from the real site. Provider references and old
  measurements do not establish available capacity.
- WordPress fixture tests use a fixture backend; actual native integration has a
  separate test. A passing CI badge does not certify a hosting account.

<img src="assets/icons/server.svg" width="20" height="20" alt=""> The separate [native repository](https://github.com/Barbarrosa/clamav/tree/upstream-optimized/compact-integration)
contains the build integration, direct database updater and optional HTTPS gateway.
