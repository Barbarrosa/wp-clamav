# WP ClamAV

A small PHP 8.2+ WordPress plugin that preserves uploads privately, scans the
original bytes, and records a decision before WordPress may publish a copy.
It supports WordPress upload/sideload APIs and Forminator 1.58.4, including
Forminator's separate AJAX uploader. No Composer dependencies, resident daemon,
root access, Python, or on-site compilation are required by the website plugin.

**Experimental implementation; actual SiteGround GrowBig deployment is not yet
qualified.** The first native build targets Linux x86-64/glibc 2.36. A matching
architecture does not establish sufficient hosting permissions or capacity.
Protection starts only after explicit configuration and enabling the plugin.

## Scan and retention behavior

- `full` uses all authenticated database rules. The optional
  `pdf-jpeg-png-pe-exclusions` profile omits only provably eligible PE rules.
  Generic, effect, dependency, custom and uncertain rules stay loaded. A file
  that reaches an excluded capability is held, including embedded executables;
  its filename or declared MIME type cannot make it clean.
- Clean means completed, qualified coverage with matching original hash and
  length. Detections, capability holds and failed/incomplete work are distinct.
  Errors and exhausted budgets do not approve publication. Administrators can
  request a full scan or make an explicit, audited manual publication decision.
- Accepted originals, partial receipts, narrative, and immutable scan history
  remain on the website indefinitely. Link expiry, update failure, deactivation,
  uninstall and resource pressure never purge them. Explicit deletion is audited.
  Back up the complete private store and WordPress database together.
- Forminator narrative can be submitted when an attachment is missing, held or
  failed. Protected file fields contain private receipt references rather than
  public paths. An unqualified Forminator version holds attachments. An empty
  protected-form list means all Forminator forms.

The plugin covers uploads through its WordPress/Forminator integration points.
Plugins that write directly to arbitrary public filesystem paths need their own
adapter. Existing public uploads are not retrospectively made private.

## Installation

1. Install the plugin ZIP from a reviewed build and activate it. It starts disabled.
2. Create a private directory outside **every** website document root, owned by
   the PHP account and mode `0700`. Configure its absolute path in **WP ClamAV**.
   Do not put originals, programs, state, or credentials in public uploads.
3. Choose local execution or an authenticated HTTPS remote gateway. Local mode
   uses this project's `compact-scan`/`compact-update` native bundle; remote mode
   streams originals to the configured service and retains the canonical local
   original. Prefer `WP_CLAMAV_REMOTE_TOKEN` in server configuration for credentials.
4. Review Diagnostics and configure the site's verified allocations. Missing
   required capacity inputs hold the affected work. There is no universal
   GrowBig memory, upload-size, storage, CPU or email default.
5. Configure Forminator form IDs, profile and notification rules. Test clean,
   EICAR, held and failed examples on a staging site before enabling protection.
6. Schedule WordPress due events with the hosting scheduler. The worker schedule
   is 30 minutes; direct FreshClam checks are spaced at least two hours and honor
   persistent upstream cooldown. WP-Cron alone depends on site traffic.

For local code bootstrap, configure the native release verification public key
and opt into the appropriate signed channel, then run updates. The installer
stages code; the native updater validates its database before selecting the
complete program/database tuple. Alternatively configure absolute paths to an
authenticated, unpacked matching native bundle. State defaults to
`<private-root>/native-state` and programs to `<private-root>/programs`.

Native and plugin updates have separate public keys. Set standard base64 Ed25519
32-byte public keys through `WP_CLAMAV_NATIVE_RELEASE_PUBLIC_KEY` and
`WP_CLAMAV_PLUGIN_RELEASE_PUBLIC_KEY`, or their settings fields. The plugin
repository is `Barbarrosa/wp-clamav`; native code comes from `Barbarrosa/clamav`.
No signing private key is distributed. Missing verification keys disable code
installation; they do not disable official database maintenance of an existing
configured native installation. See [release procedures](docs/releases.md).

## Resource configuration

Diagnostics preserves PHP observations separately from user allocations, provider
references and policy. PHP memory, native resident memory and native virtual
address space are different constraints. Confirm actual hosting limits and
measure the selected engine/database; a historical test snapshot is not a future
database bound. At least 20% measured-workload headroom is policy, not entitlement.

Configure budgets as finite nonnegative numbers using the units in their names:

| Operation | Required allocation inputs |
| --- | --- |
| Indefinite storage | `storage_bytes`, `storage_reserve_bytes`, `update_reserve_bytes`; confirm `account_remaining_bytes`, `inodes_remaining`, `inode_reserve`, `quota_observed_at` when available |
| Intake | PHP/WordPress/Forminator constraints are detected; optionally tighten `file_bytes`, `request_bytes`, `server_body_bytes`, `files_per_request` |
| Native work | `native_as_bytes`, `native_cpu_seconds`, `native_file_bytes`, `worker_wall_seconds`, `request_work_seconds`, `cleanup_seconds`, `cpu_hourly_seconds`, `cpu_daily_seconds`, `cpu_monthly_seconds` |
| Native RSS | Confirm `native_process_bytes`, `native_account_available_bytes`; larger AS caps require `measured_native_rss_bytes` and variation qualification |
| PHP/remote work | `php_work_bytes`, request/worker wall and cleanup; optionally `proxy_remaining_seconds` |
| Official updates | `update_line_bytes`, `update_state_inodes`, confirmed account bytes/inodes and inode reserve, `freshclam_connect_seconds`, `freshclam_low_speed_seconds`, `freshclam_max_attempts`; optional tighter `update_wall_seconds` |
| Code downloads | `code_staging_bytes`, `code_update_seconds`, confirmed account remainder and the request/worker deadline; plugin downloads have their separately configured byte/time budgets |
| Mail | `mail_message_bytes`, `mail_php_bytes`, `mail_message_overhead_bytes`, `mail_memory_multiplier`, `mail_recipient_hourly`, `mail_reserve_hourly`, `other_mail_hourly`; confirm `mail_provider_hourly`, optionally tighten `mail_attachment_bytes` |

Quota observations subtract subsequent private-store growth. They cannot observe
other websites or other account activity. CPU and recipient reservations are
shared by network sites; private storage allocation is divided by site count.
Storage includes metadata and inodes, with crash recovery before further
admission. Canonical JSON records have a 1 MiB format ceiling; oversized narrative
is rejected explicitly rather than truncated. Capacity pressure stops new work
and requires operator action; it never removes prior submissions.

Operational accounting also measures retained native generations, code packages,
and PHP scratch against account remainder. Native updates enforce a separate total
state ceiling while preserving 20% monitoring headroom. Monitoring complements
hosting quotas; it is not a kernel-enforced account quota. Adding network sites
requires fresh quota confirmation before shares can be redistributed.

Database freshness defaults to 48 hours as a security policy. OTP/invitation
lifetimes are independent security policies and do not alter retention.

## Notifications and testing

Multiple rules and recipients can receive each event. Original attachments and
recipient-verified review links are independently opt-in. Failed/incomplete scans
never allow attachments, embedded originals, or download grants, including resend.
GET links do not send an OTP or download bytes. Mail acceptance is not proof of
inbox delivery. See [notifications](docs/notifications.md) and
[storage accounting](docs/storage-accounting.md).

Run `php tests/run.php` for isolated contracts. Run
`python3 -m unittest discover -s tools -p 'test_*.py'` for packaging. Real WordPress,
Forminator, multisite and native tests require the explicitly marked disposable
fixtures documented by their runners. GitHub Actions builds pinned environments,
executes these checks and produces pending artifacts; protected promotion signs
the exact tested artifact without rebuilding it. No workflow deploys a live site.

The `SubmissionStore` interface separates canonical persistence from intake and
notification behavior for a future external datastore. This release implements
private on-site storage and performs no external data migration.

GPL-2.0-or-later; see [LICENSE](LICENSE).
