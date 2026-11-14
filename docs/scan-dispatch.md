<p><a href="../README.md">WP ClamAV</a> / <a href="README.md">Documentation</a></p>

<a name="bounded-scan-dispatch"></a>

# Scan admission and queues

Uploads preserve evidence; they do not grant unlimited permission to start
ClamAV. All scan entry points share admission, CPU accounting, and bounded work.

> A refused reservation stays an incomplete held result. It never authorizes
> publication, an attachment, or a download.

**On this page:** [Eligibility](#decide-which-uploads-can-start-scans) ·
[Admission](#admit-one-native-operation-at-a-time) ·
[Queue limits](#bound-the-durable-queue) ·
[Retries and recovery](#retry-without-duplicating-work) · [Testing](#verify-changes)

## Decide which uploads can start scans

Forminator originals require a committed canonical narrative and a
server-computed `scan_receipt_ids` descriptor. Receipt tokens alone cannot
authorize native work.

| Upload or lifecycle | Automatic scan behavior |
| --- | --- |
| Eligible, accepted final Forminator `active` entry | May queue only the server-authorized subset of its receipts. |
| Draft, abandoned, spam, pending-payment, or preview entry | Keeps narrative and original receipt membership; grants no scan authorization. |
| Empty original, unqualified adapter, or known intake policy violation | Remains privately held without automatic engine work. |
| Explicit administrator request for a nonempty complete policy-held original | May request a full scan; the scan cannot remove its publication policy hold. |

Preview dispatch is checked independently because preview models may also report
`active`. Canonical submission metadata records lifecycle holds.

## Admit one native operation at a time

The account slot is a nonblocking OS file lock shared across network sites. It is
acquired before original hashing, snapshot copies, or native startup. Native
descendants retain an explicit inherited descriptor: closing the PHP supervisor's
reference cannot admit another job while a surviving native process still owns it.

Maintenance signals one expiring priority record before attempting the same slot.
New scans defer until maintenance runs or the bounded intent expires. Priority
never releases a live lock.

Rolling CPU admission and native CPU, address-space, and wall limits apply in
addition to this slot. See [resource budgets](resource-constraints.md) for the
confirmed allocation requirements and maintenance reserve.

## Bound the durable queue

`wp_clamav_dispatch` is **one coalesced wake-up per site**. New uploads do not
create per-original cron events. The legacy `wp_clamav_scan_receipt` callback
converts its historical intent into the durable queue; it never runs a scanner.
The recurring worker recovers a missed wake-up.

| Control | Bound or behavior |
| --- | --- |
| Indexed pending requests | 4,096; a format-policy limit independent of hosting quotas. |
| Request state | Bounded ID index and one small marker per request; no unbounded spill directory or per-IP map. |
| Crash recovery | Index journal repairs interrupted marker creation or retirement without walking retained originals. |
| `worker_batch_files` | At most 20 jobs per wake; marker inspection is also bounded. |
| `worker_batch_wall_seconds` | May shorten confirmed `worker_wall_seconds`; cannot extend it. |
| `worker_max_attempts` | Three attempts by default, capped at 20; an operating policy, not a provider entitlement. |

A full queue holds further requests for explicit review and preserves originals
and narratives. Completed markers are operational bookkeeping and may be retired.
Originals, narratives, audit records, and immutable scan events remain until
explicit deletion.

## Retry without duplicating work

Each wake applies one absolute batch deadline to admission and every scan,
reserving cleanup time. A capacity refusal stops the wake and pauses the queue
until the retry time. The rotating cursor prevents repeatedly deferred requests
from monopolizing dispatch.

Capacity refusals do not consume an execution attempt or create notification
storms. Interrupted execution attempts remain charged in the marker. Unexpected
dispatch failures back off; exhausted requests stay held for explicit review.

Public replay cannot replace a queued profile or submission proof, reset the
next eligible time, or restart a terminal receipt. A stale automatic request is
retired when a synchronous scan has already finished the original. Terminal state
is rechecked under the per-receipt scan lock before hashes or native startup.
Explicit administrator requests preserve the actor and profile in canonical audit
records.

### Recover a missed legacy intent

Canonical recovery uses the WordPress receipt index with a bounded ascending
keyset, avoiding directory-offset walks. It pauses while the index or accounting
state is untrusted. Recovery cannot invent a missing Forminator submission
authorization; those originals stay available for explicit review. Normal
submitted requests retain their trusted descriptor in the queue marker.

## Verify changes

Run the standalone queue and admission contracts:

```sh
php tests/scan-queue.php
php tests/scan-admission.php
php tests/resource-dispatch.php
```

The [real WordPress dispatch suite](../tests/dispatch-wordpress.php) additionally
covers public flood coalescing, legacy conversion, deferred recovery, terminal
replay, submission authorization, administrator full-scan intent, stale queue
retirement, and deadlines.

That fixture uses a trusted virtual resource clock to test pacing without
sleeping. Every backend call still passes the shared resource gate. No stored
plugin setting provides a fixture bypass. Use the disposable setup documented in
[development](development.md) and [the WordPress test guide](../tests/wordpress/README.md).

**Related:** [Architecture](architecture.md) ·
[Resource budgets](resource-constraints.md) · [Retention and storage](storage-accounting.md)
