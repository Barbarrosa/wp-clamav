<p><a href="../README.md">WP ClamAV</a> / <a href="README.md">Documentation</a></p>

# Configuration reference

Configure the verified capacity of the actual website before enabling intake.
The plugin combines runtime observations, operator allocations and its own
policies; hosting-plan references never become spendable quota automatically.

For first installation, start with [getting started](getting-started.md). This
page explains the settings and budget names used by the implementation.

## Find the settings

- **Single site:** Tools → WP ClamAV.
- **Network-activated multisite:** Network Admin → Settings → WP ClamAV.

The interface has four tabs: **Uploads and review**, **Settings**,
**Notifications**, and **Capacity and diagnostics**. Settings changes require the
appropriate site or network administration capability.

## Defaults that matter

These are application defaults from [`Settings`](../src/WordPress/Settings.php),
not a deployment recommendation.

| Setting | Default | Operational meaning |
| --- | --- | --- |
| Protection | Disabled | Activation alone does not protect new uploads. |
| Backend | `local` | Requires a compatible verified native bundle and usable allocations. |
| Profile | `full` | Uses the full authenticated database. Reduced coverage is an explicit selection. |
| Signed release channel | `stable` | Preview is an explicit opt-in. |
| Automatic plugin updates | Enabled | Installation still requires the repository, verification key, budgets and WordPress filesystem permission. |
| Protected Forminator form IDs | Empty list | Protects every Forminator form while protection is enabled. |
| Storage path, verification keys and remote endpoint | Empty | Must be configured for the chosen mode. |
| Resource budgets | Empty object | Unknown capacity holds affected work; no GrowBig allocation is guessed. |
| Initial notification rule | Administrator, result-only | Selects detected, held and failed outcomes. Clean notices, attachments and links start off. Mail still needs confirmed capacity. |

Database freshness defaults to **48 hours**. Worker scheduling uses **30 minutes**;
direct update checks are spaced at least **two hours** and honor persistent
upstream cooldown. These are policies, not proof that a host permits those jobs
or that WP-Cron will run promptly.

## Paths and backends

All configured paths must be absolute. The private root must be owned by the PHP
account, mode `0700`, and outside **every** website document root. Do not place
originals, native state, programs or credentials under public uploads.

| Field | Use |
| --- | --- |
| `storage_path` | Permanent private store for originals, narrative and history. |
| `state_dir` | Native update state root, not one database generation. Defaults to `<private-root>/native-state`. |
| `programs_dir` | Private native package location. Defaults to `<private-root>/programs`. An explicit directory must already satisfy the private-directory checks. |
| `local_binary`, `updater_binary` | Optional absolute paths to matching, manually installed verified executables. Leave empty for authenticated release bootstrap. |
| `certificates_dir` | Optional matching database-certificate directory override. Otherwise the native bundle supplies it. |
| `endpoint` | Fixed authenticated HTTPS gateway base URL when the remote backend is selected. No embedded credentials, query string, fragment or redirect. |
| `remote_token` | Scan credential; prefer a server-side constant instead of storing it in settings. |

The native target is currently `linux-x86_64-glibc2.36`. Matching that target does
not establish executable permissions, aggregate memory headroom or SiteGround
compatibility. Remote mode retains the original locally and sends its bytes to
the explicitly configured service for scanning.

## Trusted server constants

These constants in server configuration or `wp-config.php` override their stored
settings. They are not required when the corresponding feature is unused.

| Constant | Value |
| --- | --- |
| `WP_CLAMAV_REMOTE_TOKEN` | Credential for the selected HTTPS scanner. |
| `WP_CLAMAV_NATIVE_RELEASE_PUBLIC_KEY` | Canonical standard-base64 encoding of the native publisher's 32-byte Ed25519 **public** key. |
| `WP_CLAMAV_PLUGIN_RELEASE_PUBLIC_KEY` | Canonical standard-base64 encoding of the plugin publisher's separate 32-byte Ed25519 **public** key. |

Keep private signing keys out of WordPress settings, source, logs and chat. A
public verification key must be obtained through a trusted operator process;
downloading one beside an untrusted release does not establish trust.

Missing release keys disable the corresponding code installation. They do not
disable direct official database maintenance of an already configured native
installation. See [releases](releases.md) for channels, tags and promotion.

## Enter resource budgets

Use **Settings → Account constraints and operating budgets**. It accepts one JSON
object containing finite, nonnegative **numbers**, not PHP size strings. Byte
allocations use bytes; durations use seconds. For example, a measured byte value
must be entered as a number, not `"128M"`.

There is deliberately no ready-to-run allocation example: copying an arbitrary
memory or CPU allowance could harm another site on the account. Record confirmed
values, operating reserves and other account use, then inspect **Capacity and
diagnostics** for the effective result. A configured upper bound is not proof
that every other required constraint is known.

### Storage and uploads

| Budget names | Purpose |
| --- | --- |
| `storage_bytes` | Allocation for retained originals and canonical metadata. |
| `storage_reserve_bytes`, `update_reserve_bytes` | Operating reserve and separate native update/code workspace allocation. Required for private intake. |
| `account_remaining_bytes`, `inodes_remaining`, `inode_reserve`, `quota_observed_at` | Confirmed account remainder, inode reserve and observation timestamp. Later private/operational growth reduces that observation. Automatic updates require known bytes and inodes. |
| `file_bytes`, `server_body_bytes`, `request_bytes`, `files_per_request` | Optional tighter upload, request-body and file-count limits, combined with detected PHP, WordPress and Forminator constraints. |

PHP `upload_max_filesize`, `post_max_size`, memory and execution settings are
observed separately. Form fields and multipart framing consume request capacity
too. Filesystem free space does not establish account quota. Reconfirm quota
observations after other account activity; unchanged settings do not restore
previously consumed space. See [storage accounting](storage-accounting.md).

### Scans and request work

| Budget names | Purpose |
| --- | --- |
| `native_process_bytes`, `native_account_available_bytes` | Confirmed native process and available account resident-memory allocations. Both are required for local execution. |
| `native_as_bytes` | Native address-space ceiling, further capped at 80% of the smaller confirmed resident-memory allocation and any tighter inherited limit. |
| `native_cpu_seconds`, `native_file_bytes` | Native job CPU allowance and per-file ceiling, also enforced while creating the scan snapshot. |
| `cpu_hourly_seconds`, `cpu_daily_seconds`, `cpu_monthly_seconds` | All three rolling CPU allocations are required for scan admission, including remote scans. Monthly accounting conservatively uses 31 days. |
| `request_work_seconds`, `worker_wall_seconds`, `cleanup_seconds` | Request/worker wall envelopes and time retained for cleanup. Work must fit after the cleanup reserve. |
| `php_work_bytes` | Additional PHP work allocation, combined with observed PHP headroom. Unknown headroom prevents work. |
| `proxy_remaining_seconds`, `cpu_remaining_seconds` | Optional tighter proxy-time or currently available CPU bounds. |
| `worker_batch_files`, `worker_batch_wall_seconds`, `worker_max_attempts` | Optional queue operating policies. Batch count and attempts are capped at 20; the batch deadline can only shorten the worker envelope. |

Scan admission uses at most **80%** of each confirmed CPU window. The remaining share is
reserved for maintenance; maintenance can use any remaining total allowance.
Global pacing and a minimum one-second scan charge prevent unlimited tiny work.
Unknown usage retains a conservative charge. A busy slot or exhausted allowance
defers work while the original remains private.

Native address space, native resident memory, PHP memory and aggregate account
memory are distinct. Historical `measured_native_rss_bytes` and
`measured_native_rss_variation_bytes` are diagnostic observations; they cannot
raise the native address-space ceiling for hostile future inputs. Application
headroom is not a kernel account quota. See [resource constraints](resource-constraints.md)
and [scan dispatch](scan-dispatch.md).

### Official database and code updates

| Budget names | Purpose |
| --- | --- |
| `update_line_bytes` | Streaming database record/line allocation. |
| `update_state_inodes` | Absolute inode allocation for the whole native state, including existing retained generations. |
| `freshclam_connect_seconds`, `freshclam_low_speed_seconds`, `freshclam_max_attempts` | Positive native network timeout and attempt bounds. |
| `update_wall_seconds` | Optional tighter native update deadline. |
| `database_max_age_seconds` | Optional freshness policy; default 172,800 seconds. |
| `code_staging_bytes`, `code_update_seconds` | Code staging/work allocations used by both native and plugin code-download paths. Each path performs its own admission and verification. |
| `update_interval_seconds`, `provider_cron_interval_seconds`, `code_check_interval_seconds` | Optional longer check spacing where supported; they cannot shorten the minimum interval or upstream cooldown. `code_check_interval_seconds` applies to native code discovery. |

Native updates also need confirmed account bytes/inodes, reserves and the local
execution allowances above. Code downloads need account remainder and bounded
PHP/network work. Old active and rollback artifacts remain charged; updates never
purge submissions or old generations to make space.

Native code comes from `Barbarrosa/clamav`; the default plugin repository is
`Barbarrosa/wp-clamav`. Database content comes directly from ClamAV distribution
sources. Code release authentication and database authentication are separate.

### Notifications

| Budget names | Purpose |
| --- | --- |
| `mail_recipient_hourly`, `mail_provider_hourly` | Application/provider hourly recipient bounds. Effective allowance uses the tighter known value. |
| `mail_reserve_hourly`, `other_mail_hourly` | Subtracted operating reserve and confirmed other account mail traffic. Unknown values prevent sending. |
| `mail_message_bytes` | Maximum complete encoded message size, including report text and MIME framing. |
| `mail_php_bytes`, `mail_memory_multiplier` | PHP assembly allocation and multiplier covering the installed mail transport. The multiplier must be at least one. |
| `mail_message_overhead_bytes` | Header and MIME framing allowance. |
| `mail_attachment_bytes` | Optional tighter limit for original attachment bytes. |

Multiple rules and recipients are supported. Each recipient gets a separate
message. Attachments and recipient-verified links are independently opt-in;
failed or incomplete scans cannot provide either. Missing complete-message or
recipient capacity queues the message rather than treating mail as unlimited.
See [notifications](notifications.md) for templates, review rules and OTP policy.

## After changing configuration

1. Save settings and inspect **Capacity and diagnostics** for unknown or limiting
   inputs. Separate provider references from confirmed allocations.
2. Test the selected profile, clean, detected, held and failed outcomes on staging.
   Check both public upload behavior and private narrative retention.
3. Verify scheduler, direct update and notification behavior with the actual host.
   Refresh quota observations when allocations or other account use change.

Network activation shares account CPU/mail coordination and native locations;
private retained-storage shares depend on site count. Adding sites requires fresh
quota confirmation before shares can be redistributed.

**Next:** [resource constraints](resource-constraints.md) ·
[troubleshooting](troubleshooting.md) · [documentation index](README.md)
