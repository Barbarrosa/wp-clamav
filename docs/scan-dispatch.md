# Bounded scan dispatch

Public upload reception does not grant permission to start unlimited scanner jobs. All scan entry points share the account dispatch slot and rolling CPU admission. The slot is acquired before original hashing, scan snapshot copies, or native startup. A refused reservation remains an incomplete held result; it never authorizes publication, an attachment, or a download.

The account slot is an OS file lock, shared across network sites and retained by native descendants through an explicit inherited descriptor. Closing the PHP supervisor's reference cannot admit another job while a surviving native process still owns that descriptor. Maintenance signals one expiring priority record before attempting the same slot; new scans defer until maintenance runs or the bounded intent expires. Priority never releases a live lock.

Forminator originals first require a committed canonical narrative and a server-computed `scan_receipt_ids` descriptor. Receipt tokens alone cannot authorize scans. Empty originals, unqualified adapters and known intake policy violations remain privately held without automatic engine work. An administrator may request a full scan for a nonempty complete policy-held original; that scan still cannot remove its publication policy hold.

The qualified Forminator lifecycle must be an accepted final `active` entry. Draft, abandoned, spam, pending-payment and preview entries retain their narrative and original receipt membership, but receive no automatic scan authorization. Preview dispatch is checked independently because preview entry models can also report `active`. Lifecycle holds are recorded in canonical submission metadata.

`wp_clamav_dispatch` is one coalesced wake-up per site. The old `wp_clamav_scan_receipt` callback only converts its historical intent into the durable queue. It never executes a scanner. New uploads create no per-original WordPress cron events. The recurring worker recovers a missed wake-up.

The private queue has a format-policy limit of 4,096 indexed requests, independent of hosting quotas. Its bounded ID index references one small marker per request. An index journal makes interrupted marker creation or retirement recoverable without traversing retained originals. A full queue holds further requests for explicit review and keeps originals and narratives. There is no unbounded spill directory or per-IP state.

Public replay cannot replace a queued profile or submission proof, reset its next eligible time, or restart a terminal receipt. Explicit administrator requests retain the actor and profile in canonical audit records. Completed dispatch markers are operational bookkeeping and may be retired; originals, narratives, audit records and immutable scan events are retained until explicit deletion.

Each wake inspects a bounded number of markers, processes at most `worker_batch_files` (capped at 20), and applies one absolute batch deadline to resource admission and each scan. `worker_batch_wall_seconds`, when configured, can shorten the confirmed `worker_wall_seconds`; cleanup time is reserved. A capacity refusal stops the wake immediately and pauses the whole queue until its retry time. The rotating cursor prevents repeatedly deferred requests from monopolizing dispatch.

Capacity refusals do not consume an execution attempt and do not generate notification storms. Interrupted execution attempts remain charged in the marker; the operating policy allows three attempts by default (`worker_max_attempts`, capped at 20). Unexpected dispatch failures back off, and exhausted requests remain held for explicit review. These are operating policies, not provider entitlements.

Legacy canonical recovery uses the WordPress receipt index with a bounded ascending keyset, avoiding directory-offset walks. Recovery pauses while the index or accounting state is untrusted. It cannot invent a missing Forminator submission authorization; those originals remain available for explicit review. Normal submitted requests retain their trusted descriptor in their queue marker.

Validation includes the standalone queue crash/replay/capacity checks and the real WordPress dispatch suite. The latter uses a trusted fixture-only virtual resource clock to test pacing without sleeping, while all backend calls still pass the shared resource gate. It covers public flood coalescing, legacy conversion, deferred recovery, terminal replay, submission authorization, administrator full-scan intent and deadline enforcement. No fixture bypass is available through plugin settings.
