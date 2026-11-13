# Plugin builds and releases

The plugin is released from its own repository, independently of the native
ClamAV fork. The initial plugin version/tag is `0.1.0` / `v0.1.0`; it is an
experimental preview. Native tags use the separate `compact-v<version>` scheme
in `Barbarrosa/clamav`. A plugin update does not activate a native program or
replace a database generation.

`compact-plugin.yml` uses a pinned Linux x86-64/PHP 8.2 builder and a pinned
MariaDB image. It runs PHP lint and contract tests, deterministic packaging tests,
and real WordPress 7.1.4/Forminator 1.58.4 HTTP upload-lifecycle tests. Fixture
downloads have fixed hashes in `tools/wordpress-fixtures.json`. The isolated
backend returns explicit test decisions; these checks validate hook behavior,
privacy and narrative retention rather than malware-detection quality. No real
mail is sent and no production database or site is used.

`tools/package.py` creates the installable `wp-clamav-<version>.zip` with a fixed
entry order, timestamp and permissions, including the GPL license and interpreted
source. A separate complete source archive includes tools/tests. The ZIP contains
no native binaries, databases, credentials, submissions or private storage.

The package's `manifest.json` records protocol 1, monotonic sequence, expiration,
channel, version, repository, integration commit, ZIP hash/size/URL and source
archive hashes. `manifest.sig` is a raw 64-byte Ed25519 signature over the exact
manifest bytes. Both channels use the same asset names; their keys and explicit
signed `channel` values differ. Plugin platform metadata is
`wordpress-php-8.2+`; actual supported/tested versions remain documented by the
plugin and its test matrix, rather than inferred from that descriptor.

CI initially uploads a **pending unsigned Actions artifact**, not an installable
GitHub release. The native and plugin qualification workflows issue GitHub build
attestations for the exact artifact and source commit. Promotion verifies the
original default-branch run and attestation, archive digest and every indexed
file, then uses the protected channel key. It never rebuilds the ZIP.

Before publication, configure `compact-preview` and `compact-stable` environments
for protected branches, with required reviewers on stable. Put a separate sodium
64-byte secret key in each environment's `COMPACT_SIGNING_KEY_B64` secret and its
base64 public key in `COMPACT_RELEASE_PUBLIC_KEY_B64`. The reviewed updater trust
root must contain the matching public key. No release keys are supplied by this
repository; missing keys or incomplete stable qualification keep publication
blocked. Private keys never belong in source, logs, public assets or chat.

Use `compact-promote.yml` with the successful qualification run ID, exact candidate
SHA-256, version and channel. It signs before creating the GitHub release, uploads
into a draft, then publishes. Preview releases are prereleases and require explicit
operator opt-in. Published tags/assets are immutable; corrections receive another
semantic version. The workflow does not deploy a WordPress site or alter its
settings.

Native packages are obtained from the fixed ClamAV repository through their own
signed manifest. They contain individual verified programs/libraries/certificates
and notices, so the native installer does not extract arbitrary archives. The
first native target is `linux-x86_64-glibc2.36`, with host libc/loader/libgcc/
libstdc++ requirements. A matching host still needs capability and account-budget
preflight. Unsupported local execution retains the configured remote or hold
route; this target does not establish universal SiteGround compatibility.

FreshClam updates come directly from ClamAV's normal distribution endpoints under
the updater's rate/backoff policy. There is no central database publisher or
requirement to upload complaint evidence to obtain signature updates.
