<p><a href="../README.md">WP ClamAV</a> / <a href="README.md">Documentation</a></p>

<a name="plugin-builds-and-releases"></a>

# Builds, updates & releases

Build once, qualify the exact artifact, then promote it with the selected channel's signing key. The plugin, native scanner and signature database have separate update paths.

> **Current status:** experimental preview candidates. A passing build is not a signed release or a SiteGround deployment qualification. Stable publication remains blocked while its qualification is incomplete.

**On this page:** [Update paths](#three-update-paths) · [Build a candidate](#1-build-and-review-a-candidate) · [Configure signing](#2-configure-signing-environments) · [Promote](#3-promote-the-tested-candidate) · [Client trust](#configure-update-clients) · [Artifact reference](#artifact-reference)

## Three update paths

| Component | Source & version identity | What changes on the website |
| --- | --- | --- |
| WordPress plugin | [`Barbarrosa/wp-clamav`](https://github.com/Barbarrosa/wp-clamav), `v<version>` tags | WordPress installs the authenticated plugin ZIP. |
| Native ClamAV bundle | [`Barbarrosa/clamav`](https://github.com/Barbarrosa/clamav/tree/upstream-optimized), `compact-v<version>` tags | Verified programs, libraries and certificates are staged; native validation selects a matching program/database tuple. |
| Signature database | Original ClamAV distribution endpoints | The on-site FreshClam component authenticates and prepares a new generation under its rate and backoff policy. |

A plugin update does not activate a native program or replace a database generation. Database maintenance needs no secondary database publisher and does not require uploading submissions or complaint evidence.

## 1. Build and review a candidate

The [qualification workflow](../.github/workflows/compact-plugin.yml) runs on pushes to `main`, pull requests and manual dispatch. It uses a pinned Linux x86-64/PHP 8.2 builder and pinned MariaDB image.

| Gate | Evidence |
| --- | --- |
| PHP and packaging | Strict PHP lint, contract tests and deterministic packaging checks. |
| Actual WordPress integration | WordPress 7.1.4/Forminator 1.58.4 HTTP lifecycle, dispatch, notification persistence, plugin update hooks and multisite checks. |
| Build provenance | GitHub attestation binding the candidate and manifest to the source commit. |

The fixture archives and hashes are pinned in [`tools/wordpress-fixtures.json`](../tools/wordpress-fixtures.json). Its backend returns explicit test decisions: this suite checks hooks, privacy and retention, not malware-detection quality. It sends no real mail and uses no production site or database.

Successful trusted default-branch runs upload two Actions artifacts:

- **`compact-plugin-pending`** — candidate transport and its SHA-256.
- **`compact-plugin-test-evidence`** — qualification report and test logs.

Review the evidence, source commit and candidate digest before promotion. Pull-request runs do not create release candidates. An Actions candidate is a review artifact; the automatic updater requires a signed, published release.

## 2. Configure signing environments

This setup requires repository access to **Settings → Environments** and branch protection. The repository supplies no release keys.

Create `compact-preview` and `compact-stable` in the plugin repository:

| Setting | Required value |
| --- | --- |
| Deployment branches | Restrict both environments to protected branches; protect the publishing branch. |
| Stable approval | Add required reviewers to `compact-stable`. |
| Environment **secret** | `COMPACT_SIGNING_KEY_B64`: base64-encoded sodium 64-byte Ed25519 secret key. |
| Environment **variable** | `COMPACT_RELEASE_PUBLIC_KEY_B64`: matching base64-encoded 32-byte public key. |

Use separate channel keys. Provision the native repository's trust and release environments independently; plugin and native signing keys serve different update paths.

Distribute the matching public key to the reviewed update-client configuration. Private keys belong only in protected secret storage: never source, logs, public assets or chat. Missing keys, missing environment protection or incomplete stable qualification stop publication.

## 3. Promote the tested candidate

Use the [promotion workflow](../.github/workflows/compact-promote.yml) with values taken from the reviewed qualification run:

| Workflow input | Value to supply |
| --- | --- |
| `run_id` | Successful plugin qualification run from this repository's current default branch. |
| `candidate_sha256` | SHA-256 of `wp-clamav-<version>-candidate.tar.gz`, as recorded by qualification. |
| `version` | Exact candidate version, matching its manifest and plugin header. |
| `channel` | `preview`, or `stable` only after stable qualification and protection are complete. |

**Enter the version explicitly.** The workflow's version-field default is not evidence of the candidate's version.

<details>
<summary>Dispatch with the GitHub CLI</summary>

Set `qualified_run_id`, `candidate_sha256` and `release_version` from the reviewed artifact, then run:

```sh
gh workflow run compact-promote.yml \
  --repo Barbarrosa/wp-clamav \
  --ref main \
  -f run_id="$qualified_run_id" \
  -f candidate_sha256="$candidate_sha256" \
  -f version="$release_version" \
  -f channel=preview
```

The GitHub Actions web interface accepts the same four inputs.

</details>

Promotion proceeds in this order:

1. Verify the successful trusted default-branch run and protected channel environment.
2. Verify GitHub provenance, the candidate archive digest, commit/version/channel identity and every indexed file.
3. Sign the exact manifest bytes with the protected channel key.
4. Create or resume a draft release, upload the verified assets, then publish it.

The workflow never rebuilds the ZIP. Preview releases are GitHub prereleases and require explicit operator opt-in. Published tags and assets are immutable; a correction gets a new semantic version. Promotion does not deploy a WordPress site or change site settings.

## Configure update clients

Keep the two public trust roots distinct:

| Client | Server constant | Release repository |
| --- | --- | --- |
| Plugin updates | `WP_CLAMAV_PLUGIN_RELEASE_PUBLIC_KEY` | `Barbarrosa/wp-clamav` by default. |
| Native code updates | `WP_CLAMAV_NATIVE_RELEASE_PUBLIC_KEY` | Fixed `Barbarrosa/clamav` release path. |

Both constants accept canonical base64 Ed25519 32-byte public keys; equivalent settings fields are available. The selected `release_channel` must match the signed manifest's channel. Stable is the default; preview requires an explicit selection.

The plugin update client authenticates the manifest, sequence, expiration, version and package identity before handing a verified ZIP to WordPress. Native installation verifies individual files instead of extracting an arbitrary archive. Staging native code does not activate it: database validation must finish before the complete program/database tuple is selected.

Missing code-verification keys disable the corresponding code-installation path. They do not disable official database maintenance for an already configured native installation.

### Native host compatibility

The first native target is `linux-x86_64-glibc2.36`, with host libc, loader, libgcc and libstdc++ requirements. Matching architecture alone does not establish executable permissions, sufficient memory or hosting compatibility.

Confirm local capabilities and account budgets before enabling native execution. Unsupported execution leaves files held unless an authenticated remote backend is configured. See [getting started](getting-started.md) and [resource constraints](resource-constraints.md).

## Artifact reference

[`tools/package.py`](../tools/package.py) writes a deterministic ZIP with fixed entry order, timestamps and permissions. The ZIP includes the GPL license and interpreted plugin source; it excludes native binaries, databases, credentials, submissions and private storage. A separate source archive includes the tools and tests.

| File | Purpose |
| --- | --- |
| `wp-clamav-<version>.zip` | WordPress plugin package. |
| `wp-clamav-<version>-source.tar.gz` | Complete corresponding plugin source, including development tools and tests. |
| `manifest.json` | Exact signed release identity and inventory. |
| `manifest.sig` | Raw 64-byte Ed25519 signature; created during protected promotion. |
| `qualification.json` | Source-bound check results and qualification gates. |
| `provenance.json` | Build source and origin metadata. |
| `sbom.spdx.json` | SPDX software bill of materials. |
| `bundle-index.json` | Candidate transport index checked during promotion. |

The protocol-1 manifest records the monotonic sequence, expiration, channel, version, repository, integration commit, ZIP size/hash/URL and source-archive hashes. Verification uses its **exact bytes**, not reserialized JSON. Both channels use the same asset naming scheme with separate keys and explicit signed channel values.

The platform descriptor `wordpress-php-8.2+` identifies the package family. It does not replace the documented support requirements and actual test matrix.

---

**Continue:** [Development guide](development.md) · [Integration tests](../tests/wordpress/README.md) · [Resource constraints](resource-constraints.md)
