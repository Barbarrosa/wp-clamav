<p><a href="../README.md">WP ClamAV</a> / <a href="README.md">Documentation</a></p>

<a name="retained-storage-and-work-accounting"></a>

# Retention and storage accounting

Understand what the plugin retains, how it admits new writes, and why a stale or
incomplete quota observation holds further work.

> Originals, receipts, immutable scan events, narratives, and audit records remain
> until explicit authorized deletion. Resource pressure never purges them.

**On this page:** [Storage boundaries](#know-what-is-counted) ·
[Crash recovery](#admit-writes-and-recover-interruptions) ·
[Quota observations](#refresh-remaining-quota-observations) ·
[Update workspace](#bound-operational-and-update-storage) ·
[CPU accounting](#expire-work-counters-without-expiring-data) ·
[Testing](#verify-changes)

## Know what is counted

| Storage | Accounting and authority |
| --- | --- |
| Canonical originals, receipts, events, narrative, audit records | Authoritative filesystem records; retained-data ledger. |
| WordPress receipt index | Advisory. Index failure cannot make a canonical original disappear from administration. |
| `scratch`, `programs`, `native-state` | Separate operational reservations; still consume physical account storage. |
| Operational counters and dispatch bookkeeping | May expire or be retired without deleting submission data. |

Retained `bytes` is the greater of logical and allocated bytes, where the
filesystem exposes allocation information. Both measurements remain visible.
Inodes and metadata count too. Filesystem free space does not establish account
quota. Existing active and rollback artifacts must not be charged twice when
calculating additional update reserve.

Canonical JSON records have a **1 MiB** format/read ceiling. Oversized records are
rejected before writing. Intake reserves receipt and final-record overhead as well
as original bytes; later event, narrative, and audit writes also require metadata
admission. Failure to record a final result cannot authorize publication.

<a name="crash-safe-usage"></a>

## Admit writes and recover interruptions

1. A canonical mutation takes the accounting lock before admission/receipt locks.
2. It persists a dirty marker before writing data.
3. Normal operations track file and parent-directory changes, including metadata,
   inodes, and allocated blocks where available.
4. Successful accounting publication clears the interruption state.

A dirty or legacy ledger cannot admit intake using stale totals. Recovery uses a
constant-memory retained-entry iterator and bounded checkpoints, holding mutations
until inventory is complete. `reconcileUsage(entries, seconds)` accepts a confirmed
worker allowance; normal recovery takes a small bounded step. Unexpected directory
changes invalidate the partial snapshot instead of silently omitting entries.

Only `.usage-record-*` files created by interrupted accounting publication are
disposable control state. Unknown `.record-*` files might contain submitter
metadata: they stay retained and charged. Index repair is marked separately, and
canonical listing remains available while the index is untrusted.

<a name="remaining-quota-observations"></a>

## Refresh remaining quota observations

`QuotaSnapshot` records the private-store byte/inode baseline for confirmed
`account_remaining_bytes`, `inodes_remaining`, and optional `quota_observed_at`.
Every later admission subtracts positive growth from that baseline.

| Change | Effect |
| --- | --- |
| Reuse unchanged settings | Does not restore the original remaining quota. |
| Confirm a new value or observation timestamp | Establishes a new baseline. |
| Change unrelated settings | Does not reset the baseline. |
| Change operational roots | Requires an explicitly confirmed fresh observation. |
| Upgrade a canonical-only snapshot with existing operational artifacts | Requires fresh confirmation; it cannot silently create a favorable baseline. |

The canonical observation covers this store. With configured operational roots,
subsequent operational growth also reduces confirmed account bytes and inodes.
The retained `storage_bytes` allocation continues to count canonical data only.
Repeated generations and code updates cannot reuse the original account remainder.

Other websites, databases, backups, and account activity can change quota
independently. Diagnostics must disclose this limitation and require refreshed
observations or an account-scoped allocator. Unknown capacity is never unlimited.

<a name="configured-operational-storage"></a>

## Bound operational and update storage

`ResourcePolicy::setOperationalPaths()` supplies explicit native-state, program,
and private-scratch roots. `OperationalStorage` measures their logical bytes,
allocated blocks, and file/directory inodes separately. Duplicate or nested roots
count once; original object directories are never traversed.

A root cannot contain the canonical store or overlap retained namespaces. Only
the store's separately reserved `native-state`, `programs`, and `scratch`
namespaces may sit inside it.

### Complete the observation before admitting work

Each observation is bounded to **1,000 entries** and **0.25 seconds**. These are
application work policies, not provider capacity. Measurements and roots appear in
administrator diagnostics.

The following conditions hold admission rather than authorize from partial counts:

- Symlinks in roots, ancestors, or descendants; hard links; special files.
- Unreadable or unknown allocated-block information.
- Changing directory contents, excessive depth, or an exhausted observation budget.

Missing future directories count as zero only when their existing parent can be
inspected. Nothing is purged automatically to make an observation fit.

### Allocate native update workspace

`ResourcePolicy::updateStorageLimits($statePath)` derives these separate controls:

| Control | Includes or requires |
| --- | --- |
| `max_state_bytes` | Absolute state ceiling, including existing cache, generations, and native scratch. |
| `max_state_inodes` | Explicit `update_state_inodes` allocation and confirmed remaining inodes after `inode_reserve`. |
| `disk_reserve_bytes` | Minimum physical reserve from `storage_reserve_bytes`, not the update allocation. |

Other operational artifacts, including program releases and PHP scratch, reduce
the total update allocation. The state ceiling also fits measured current state
plus confirmed account remainder after operating reserves. Unknown account quota
or inode allocation holds automatic updates.

The native monitor applies its own **20%** ceiling headroom and publishes no new
generation when bounded work cannot fit. Concurrent operational writers still
need their reservations and monitors: observation alone is not an account-wide
transactional quota service. Other hosting activity requires fresh observations.
Existing free space and retained artifacts remain relevant while updates are held.

<details>
<summary>Supplemental usage integration</summary>

`QuotaSnapshot` accepts an optional supplemental-usage callback. Confirmed account
bytes and inodes are reduced by subsequent growth in both canonical storage and
the configured operational namespaces. This does not expand the retained-data
`storage_bytes` allocation.

</details>

<a name="cpu-work-reservations"></a>

## Expire work counters without expiring data

`WorkLedger` reserves CPU against explicit `cpu_hourly_seconds`,
`cpu_daily_seconds`, and `cpu_monthly_seconds`. All three are required for
automatic native work; provider fair-use thresholds are not entitlements.

Pending reservations count fully. Completion records measured CPU; unknown
consumption stays charged at the reserved bound. An abandoned bounded job settles
conservatively when its lease ends, and late completion cannot refund it.
Milliseconds round upward. Minute buckets include the full overlapping minute
in rolling one-hour, one-day, and 31-day windows.

Atomic reservation locks prevent simultaneous work from spending the same
allowance. Unexpected state, overflow, unsupported clocks, or missing capacity
hold work instead of creating a fresh budget. The compact ledger expires only
counters beyond the longest window; originals and immutable scan history remain.
For pacing and maintenance reserves, see [resource budgets](resource-constraints.md).

## Verify changes

```sh
php tests/storage-accounting.php
php tests/operational-storage.php
php tests/resource-ledgers.php
```

These suites exercise retained mutations and recovery, operational observations,
quota baselines, and bounded CPU accounting. See [development](development.md) for
the full workflow.

**Related:** [Resource budgets](resource-constraints.md) ·
[Scan admission and queues](scan-dispatch.md) · [Troubleshooting](troubleshooting.md)
