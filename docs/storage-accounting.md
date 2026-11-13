# Retained-storage and work accounting

Canonical originals, receipts, immutable scan events, narratives and audit records are retained until an explicit authorized deletion. Private storage also has bookkeeping files and an advisory WordPress index. The filesystem records are authoritative; an index failure cannot make an original disappear from administration.

## Crash-safe usage

Each canonical mutation takes the accounting lock before admission/receipt locks and persists a dirty marker before writing data. Normal operations track file and parent-directory changes, including metadata, inodes and allocated blocks where the filesystem exposes them. `bytes` is the greater of logical and allocated retained bytes; the two measurements remain separately visible.

An interrupted mutation leaves the marker behind. A dirty or legacy ledger cannot authorize intake from stale totals. Recovery enumerates retained entries with a constant-memory iterator, writes bounded checkpoints, and holds mutations until the inventory is complete. `reconcileUsage(entries, seconds)` accepts a confirmed worker allowance; normal recovery uses a small bounded step. Unexpected directory changes invalidate the partial snapshot rather than silently omitting files.

Only `.usage-record-*` files created by interrupted accounting publication are disposable control state. Unknown `.record-*` files might contain submitter metadata and remain retained and charged. Index recovery is marked separately; canonical listing remains available while the index is untrusted.

`scratch`, `programs` and `native-state` are explicitly outside the retained-data ledger. They require their own scan/update/code reservations and still consume the account's physical storage. Filesystem free space is not necessarily account quota. Existing active/rollback artifacts must not be charged twice when calculating additional update reserve.

JSON canonical records have a 1 MiB format/read bound. Oversized records are rejected before writing, rather than committed unreadably. Intake reserves receipt/final-record overhead as well as original bytes; later event/narrative/audit writes also invoke metadata admission. An error recording a final result cannot turn that result into publication permission.

## Remaining quota observations

`QuotaSnapshot` records the private-store byte/inode baseline associated with confirmed `account_remaining_bytes`, `inodes_remaining` and optional `quota_observed_at`. Every later admission subtracts positive private-store growth from that observation. Reusing unchanged settings does not restore the original remainder. A new confirmed value or observation timestamp establishes a new baseline; unrelated setting changes do not.

These observations cover this canonical store. Other sites, databases, backups, code generations and account activity may change hosting quota independently; diagnostics must disclose that limitation and request refreshed observations or an account-scoped allocator. Unknown capacity is not represented as unlimited.

## CPU work reservations

`WorkLedger` reserves bounded CPU before native work against explicit `cpu_hourly_seconds`, `cpu_daily_seconds`, and `cpu_monthly_seconds` allocations. All three are required for automatic native work; provider fair-use thresholds are not entitlements.

Pending reservations count fully. Completion records measured CPU; unknown actual consumption is charged at the reserved bound. A bounded abandoned job is conservatively settled when its lease ends. Repeated late completion cannot refund that charge. Minute buckets conservatively include the full overlapping minute in rolling one-hour, one-day and 31-day windows. CPU milliseconds round upward.

The compact operational ledger expires counters outside the longest window; that cleanup never deletes submission data or immutable scan history. Atomic reservation locks prevent simultaneous jobs from spending the same allowance. Unexpected state, arithmetic overflow, unsupported clocks or missing capacity hold work instead of inventing a fresh budget.

## Configured operational storage

`ResourcePolicy::setOperationalPaths()` explicitly supplies native state, native programs and private scratch roots. `OperationalStorage` measures their actual logical bytes, allocated blocks and file/directory inodes separately from retained originals. Nested or duplicate operational roots count once. A configured root cannot contain the canonical store or overlap retained namespaces; only the store's separately reserved `native-state`, `programs` and `scratch` namespaces may sit inside it. No original object directory is traversed.

Each observation has an implementation work bound of 1,000 entries and 0.25 seconds. These are application policy limits, not provider capacity. Missing future directories count as zero only when their existing parent can be inspected. Symlinks in roots, ancestors or descendants, hard links, special files, unreadable/unknown allocated-block information, changing directory contents, excessive depth, or an exhausted observation budget hold admission. Partial counts never authorize an upload or update. Measurements and their roots appear in administrator diagnostics. Nothing is purged automatically to make an observation fit.

`QuotaSnapshot` accepts an optional supplemental-usage callback. Confirmed account bytes and remaining inodes are reduced by subsequent growth across canonical storage **and** the configured operational namespaces. The retained `storage_bytes` allocation still counts canonical data only. Repeated generation and code updates cannot reuse the original account remainder. An unchanged quota observation cannot silently change its operational roots; explicitly confirming a fresh observation is required. Upgrading a canonical-only snapshot with already-existing operational artifacts also requires a fresh confirmation rather than silently establishing a favorable baseline.

`ResourcePolicy::updateStorageLimits($statePath)` supplies absolute `max_state_bytes` and `max_state_inodes` ceilings and a separate `disk_reserve_bytes` minimum. State includes existing database cache, generations and native scratch. The total update allocation is reduced by other operational artifacts such as program releases and PHP scratch; the state ceiling is also constrained by measured current state plus confirmed account remainder after operating reserves. Inodes require an explicit `update_state_inodes` allocation and confirmed remaining inodes after `inode_reserve`. Unknown account quota or inode allocation holds automatic updates. `disk_reserve_bytes` uses `storage_reserve_bytes`, not the update allocation.

The native monitor enforces its own 20% ceiling headroom and writes no new generation when the bounded work cannot fit. Concurrent operational writers require those reservations/monitors; a filesystem observation alone is not an account-wide transactional quota service. Other hosting-account activity still requires fresh observations. Existing physical free space and all retained artifacts remain relevant even when updates are held.
