<p><a href="../README.md">WP ClamAV</a> / <a href="README.md">Documentation</a></p>

<a name="resource-diagnostics-and-notification-capacity"></a>

# Resource budgets and diagnostics

Confirm the resources available to this installation before enabling native work
or email. Diagnostics keeps measured facts, your allocations, implementation
policies, and provider references separate.

> A known upper bound does not authorize work while another required constraint
> is unknown. Filesystem free space and unbounded PHP settings do not establish
> available hosting capacity.

**On this page:** [Diagnostics](#read-diagnostics) ·
[GrowBig references](#confirm-hosting-allocations) ·
[Scanner admission](#bound-scanner-cpu-and-memory) ·
[Mail capacity](#budget-the-complete-email) ·
[Developer reference](#developer-reference) · [Testing](#verify-changes)

## Read Diagnostics

| Fact class | Meaning |
| --- | --- |
| `observed` | Runtime observations, including PHP limits and capabilities. |
| `user` | Explicit operator allocations confirmed for the installation. |
| `policy` | Application headroom, format limits, and effective calculations. |
| `provider` | Sourced hosting references; unconfirmed references do not set operative limits. |

Recommendations use only known, comparable constraints. A partial recommendation
is an upper bound, not proof that a job can run. Missing multipart framing leaves
the exact file payload allowance unknown. Unknown other-site mail traffic prevents
a usable hourly notification allocation.

Keep these memory scopes distinct: PHP allocation, native virtual address space,
native process resident memory (RSS), and remaining account resident memory.
One does not substitute for another.

Application policies include a **1 MiB** canonical JSON format/read ceiling and
at least **20%** work headroom, with additional measured variation where
appropriate. These are implementation policies, not hosting entitlements.

## Confirm hosting allocations

The catalog includes the following **reference-only** GrowBig values, dated
**2026-11-08**. They are unconfirmed for the site and excluded from every
operative recommendation.

| Documented reference | Value | Source |
| --- | --- | --- |
| Process resident memory | 768 MiB; confirm units | [SiteGround fair use](https://www.siteground.com/kb/fair-use-siteground-hosting/) |
| Soft CPU thresholds | 2,000 seconds/hour; 20,000/day; 600,000/calendar month | [SiteGround fair use](https://www.siteground.com/kb/fair-use-siteground-hosting/) |
| Total account inodes | 400,000; not remaining capacity | [SiteGround fair use](https://www.siteground.com/kb/fair-use-siteground-hosting/) |
| Account mail recipients | 400/hour, including other site and mailbox traffic | [SiteGround email policy](https://www.siteground.com/kb/acceptable-use-sitegrounds-email-service/) |
| Cron interval | 30-minute minimum reference | [SiteGround cron jobs](https://www.siteground.com/kb/cron-jobs/) |

Confirm the current plan, provider units and policy, and resources used elsewhere
on the account before entering allocations. The CPU ledger conservatively uses
rolling **31-day** accounting, independently of the provider calendar-month
reference. Unknown quotas remain unknown.

<a name="account-scanner-admission-and-maintenance-capacity"></a>

## Bound scanner CPU and memory

Every local or remote scan acquires one nonblocking account dispatch slot before
hashing, copying, or starting native work. The slot covers preparation, execution,
termination/reaping, final verification, and accounting. A live native descendant
retains its inherited descriptor; a deadline never releases a live slot.

An unbound coordinator or unavailable slot produces `ResourceDeferred` with a
reason and retry time. The original stays private, publication is denied, and
retry must be bounded. A capacity refusal is not a scanner verdict.

| Control | Operative policy |
| --- | --- |
| Rolling CPU windows | Explicit hourly, daily, and monthly allocations; total work must fit each. |
| Scan share | At most 80% of each allocation; at least 20% remains reserved for maintenance. |
| Global scan pacing | Slowest rate of scan-allocated CPU divided by each window duration; no growing per-IP or submitter map. |
| Admission charge | Larger CPU/wall request, including the bounded work envelope. |
| Completion charge | Measured preparation + child + finalization CPU, with a one-second scan floor. |
| Unknown consumption | Full reservation remains charged; prior pacing debt is preserved. |
| Remote consumption | Conservatively charge the service wall envelope because website code cannot observe remote CPU. |
| Native address space | At most 80% of the smaller confirmed process/account resident-memory allocation. |

Trusted cron does not promote submitted scans into maintenance work. Native
historical RSS is diagnostic only: it cannot justify a larger address-space cap
for hostile future inputs. A larger mapping allowance needs a separately verified
runtime RSS-enforcement design.

CPU snapshots use bounded packed total/scan buckets. Legacy unclassified work
counts conservatively against the scan pool. Admission counters change only when
work is admitted. Byte parsing checks exact integer arithmetic before
multiplication; unsupported CPU magnitudes and nonfinite allocations are rejected.

For queue bounds, maintenance priority, and retry behavior, see
[scan admission and queues](scan-dispatch.md).

## Budget the complete email

Attachment capacity alone is insufficient: the complete MIME message and PHP
assembly must also fit before a recipient token is consumed.

| Check | When it runs |
| --- | --- |
| `attachmentAllowed($originalBytes, $encodedBytes)` | Early, before copying a private original. |
| `messageAllowed($attachedOriginalBytes, $estimatedWireBytes)` | Against the complete message, including result-only and OTP messages. |
| `consumeMail($coordinatorStore, $recipient)` | After complete-message capacity passes. |

These checks require explicit message, framing, assembly, and memory-multiplier
allocations. Unknown capacity defers sending. Result-only and OTP messages have
zero attached-original bytes regardless of retained file size.

An oversized attachment is omitted with explanatory text. An independently
enabled OTP link may remain; the plugin never enables sharing as an implicit
fallback. Missing complete-message capacity defers the email.

The estimate includes text transfer encoding, MIME base64/CRLF wrapping, header
framing, and the manager's final-eligibility explanation. The installed mail
transport and filters must fit the configured framing allowance and assembly
multiplier; these are not inferred hosting facts. The final WordPress adapter
restores the intended recipient, plain text, and authorized originals so other
filters cannot silently add copies or HTML embeds.

Mail transport acceptance is not inbox delivery. A crash between acceptance and
durable acknowledgment can cause repeated delivery. Operational counters may
expire; originals, scan records, and submissions never expire automatically.

## Developer reference

<details>
<summary>Constraint catalog: fields and effective values</summary>

`ConstraintCatalog::build($observations, $allocations, $effective, $observedAt)`
accepts `ResourcePolicy::detect()` observations and explicit budget values.
Each fact includes its value, unit, scope, classification, provenance,
observation timestamp, unknown flag, and limiting flag.

Additional nonnumeric runtime capabilities remain configuration facts. Effective
policy values are recorded separately; callers supply their unit and scope.

</details>

<details>
<summary>Mail guard integration and exact estimate</summary>

```php
$guard = new MailCapacityGuard($budgets, $policy->phpHeadroom());
if (!$guard->messageAllowed($attachedOriginalBytes, $estimatedWireBytes)) {
    return false;
}
return $policy->consumeMail($coordinatorStore, $recipient);
```

`estimatedMessageBytes($subject, $body, $actualAttachmentSizes, $overhead)` uses a
conservative fourfold text transfer/folding bound, MIME base64 plus CRLF attachment
encoding, and one caller-supplied header/framing allowance. The manager reserves a
small final-eligibility explanation.

The guard checks
`(actual original bytes + estimated complete message bytes) × assembly multiplier`
against both current PHP headroom and the configured assembly allocation.

</details>

<details>
<summary>Resource-policy lifecycle and fixture clock</summary>

`ResourcePolicy::acquireDispatch()` returns a `DispatchLease`. Its `release()` is
idempotent and closes the owner's reference; destruction/process exit also closes
it. The inherited native reference retains the lock through process exit.

`beginScan()` reserves scan work; `beginNative()` reserves maintenance work.
Both settle through the shared account ledger using `finishNative()`.

`withDeadline($absoluteMonotonicSeconds, $callback)` scopes a batch deadline and
restores the prior context in `finally`. Native and remote wall limits shrink to
remaining monotonic time while retaining cleanup reserve. Nested deadlines may
shorten, never extend, their parent. Insufficient time raises
`ResourceDeferred('deadline_exhausted', ...)`.

The optional third `ResourcePolicy` constructor callable supplies the CPU-ledger
clock for isolated fixtures. No stored setting or request value disables pacing.

</details>

## Verify changes

```sh
php tests/resource-constraints.php
php tests/resource-dispatch.php
php tests/notifications.php
```

These cover diagnostics, admission, mail assembly, OTP, race handling, and asset
policy. See [development](development.md) for the full verification workflow.

**Related:** [Getting started](getting-started.md) ·
[Retention and storage](storage-accounting.md) · [Notifications](notifications.md)
