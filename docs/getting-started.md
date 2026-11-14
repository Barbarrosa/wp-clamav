<p><a href="../README.md">WP ClamAV</a> / <a href="README.md">Documentation</a></p>

# Get started

Configure and qualify a **staging site** before enabling upload protection on a
live site. The plugin starts disabled; activation alone does not protect uploads.

**On this page:** [Compatibility](#check-compatibility) · [Install](#1-install-a-reviewed-build) ·
[Private storage](#2-create-private-storage) · [Backend](#3-choose-a-scanning-backend) ·
[Configuration](#4-configure-allocations-and-behavior) · [Qualification](#5-qualify-the-staging-site) ·
[Scheduling](#6-enable-protection-and-schedule-work)

## Check compatibility

| Check | Requirement |
| --- | --- |
| Website PHP | 8.2 or newer. Check required capabilities in **Capacity and diagnostics**. |
| WordPress | Plugin header minimum 6.4; the pinned integration fixture uses 7.1.4. |
| Forminator, if used | Adapter qualified for 1.58.4. Other versions hold attachments pending qualification. |
| Private storage | Owner-only filesystem storage outside every website document root. |
| Local execution | Compatible native bundle, process execution support, and confirmed CPU/RAM/work limits. |
| Remote execution | PHP cURL, an authenticated HTTPS gateway and confirmed website/service work allocations. |
| Scheduling | A way to run due WordPress events reliably; traffic alone is not a schedule guarantee. |

The first native target is Linux **x86-64 / glibc 2.36**. Architecture compatibility
does not establish executable permissions, provider approval or sufficient memory.
There is no universal GrowBig allocation that can be copied into settings.

## 1. Install a reviewed build

Install the plugin ZIP through WordPress or your normal reviewed deployment
process, then activate it. Open:

| Installation | Administration location |
| --- | --- |
| Single site | **Tools → WP ClamAV** |
| Network activated multisite | **Network Admin → Settings → WP ClamAV** |

Use **Settings** for the backend and allocations, **Notifications** for delivery
rules, **Capacity and diagnostics** for observations, and **Uploads and review**
for retained originals and decisions.

CI creates pending experimental artifacts. A candidate artifact is not an
installable signed release. Read [the release guide](releases.md) before choosing
a candidate or configuring an automatic update channel.

## 2. Create private storage

Choose an absolute directory owned by the PHP account, with mode `0700`, outside
**all** website document roots on the account. Enter it as **Private storage
directory**. Use your host's file-management or shell facilities to create it.

This is the permanent on-site datastore. It contains originals, receipts,
submission narrative and history; it is not a temporary-upload directory.
Native state and programs default to:

```text
<private-root>/
├── native-state/    # database generations and updater state
├── programs/        # authenticated native code packages
└── …                # canonical originals, receipts and control records
```

An absolute path is not sufficient by itself: verify ownership, permissions,
document-root separation, available bytes and inodes. Network activation creates
site namespaces while sharing the account coordinator and native state.
See [storage and backups](storage-accounting.md).

## 3. Choose a scanning backend

| | Local native scanner | Authenticated HTTPS service |
| --- | --- | --- |
| Work runs on | The website account | A separately deployed gateway |
| Website needs | Verified `compact-scan` / `compact-update` bundle, certificates and process execution | PHP cURL, fixed HTTPS endpoint and scan credential |
| Original data | Remains private on the website | Remains on the website and is streamed to the service for scanning |
| Capacity | Confirm native process/account memory, CPU and deadlines | Confirm website transfer/work limits and the gateway's separate allocations |

### Local setup

Use the matching bundle from the
[native integration](https://github.com/Barbarrosa/clamav/tree/upstream-optimized/compact-integration).
Either configure absolute paths to an authenticated unpacked bundle, or configure
the native release public key and an available signed channel for bootstrap.

Leave the certificate override empty to use the matching bundle's certificates.
The updater must validate the database before selecting a complete program/database
tuple. An arbitrary stock `clamscan` executable is not this backend's protocol.

Missing code-verification keys block code installation. They do not block direct
official database maintenance of an already configured native installation.

### Remote setup

Deploy the [optional gateway](https://github.com/Barbarrosa/clamav/tree/upstream-optimized/compact-integration/service)
and configure its fixed HTTPS endpoint. Prefer `WP_CLAMAV_REMOTE_TOKEN` in trusted
server configuration over storing a credential in plugin settings. The gateway
has separate Python, proxy, native-runtime and resource requirements.

## 4. Configure allocations and behavior

1. Inspect **Capacity and diagnostics**, then confirm the provider/account limits
   and capacity available after other website activity.
2. Enter numeric allocations in **Account constraints and operating budgets**.
   Use the [configuration reference](configuration.md); do not copy test budgets.
3. Keep `full`, the default profile, or deliberately select
   `pdf-jpeg-png-pe-exclusions`. The reduced profile holds files when applicability
   cannot be established; it does not clear an embedded executable by its filename.
4. Select protected Forminator form IDs. An empty list protects all Forminator forms.
5. Configure notification rules and recipients. Attachments and review links are
   separate opt-ins, subject to scan eligibility and mail capacity.

Unknown allocations hold the affected work. Reaching a budget preserves prior
submissions rather than deleting them. See [resource policy](resource-constraints.md)
and [notification eligibility](notifications.md).

## 5. Qualify the staging site

After configuring the backend and allocations, **enable protection on the staging
site** before testing upload paths. Protected hooks are inactive while the plugin
is disabled. Run due WordPress events there so queued scans, updates and
notifications can execute during the checks.

Use harmless fixtures and the standard EICAR test string where appropriate. Check
each upload path your site actually uses, including Forminator's AJAX uploader.

- [ ] A known clean original receives the expected complete, qualified decision.
- [ ] EICAR is detected and cannot be automatically published.
- [ ] An unsupported or excluded-capability example remains held.
- [ ] A timeout, native failure or unavailable budget leaves the original private.
- [ ] Forminator narrative survives a missing, held or failed attachment.
- [ ] Draft, abandoned, spam and preview entries do not start automatic scans.
- [ ] Result-only notifications work; failed/incomplete scans have no attachment or download link.
- [ ] Native and database update failures retain the last valid tuple.
- [ ] Backups cover the complete private store and the WordPress database together.

The repository's [disposable fixture suites](../tests/wordpress/README.md) help
developers reproduce these behaviors. Their test credentials, mu-plugin routes,
virtual clocks and allocations belong only in isolated fixtures.

## 6. Enable protection and schedule work

After staging qualification, apply the reviewed configuration to the live site,
enable protection in **Settings**, and arrange for the hosting scheduler to run
due WordPress events. The recurring worker uses a 30-minute
schedule. Forminator uploads share a coalesced dispatch wake-up; database checks
are spaced at least two hours apart and honor persisted upstream cooldown.
Provider scheduling rules may lengthen those intervals.

WP-Cron depends on traffic. Use your host's supported WordPress scheduling method
and confirm the resulting timestamps and outcomes in diagnostics. No workflow in
this repository deploys or configures a live site.

Next: [Configuration](configuration.md) · [Troubleshooting](troubleshooting.md) ·
[Storage and retention](storage-accounting.md)
