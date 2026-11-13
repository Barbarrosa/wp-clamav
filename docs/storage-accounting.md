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
