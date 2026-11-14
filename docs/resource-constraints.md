# Resource diagnostics and notification capacity

`ConstraintCatalog::build($observations, $allocations, $effective, $observedAt)` returns facts with a value, unit, scope, classification (`observed`, `user`, `policy`, `provider`), provenance, observation timestamp, unknown flag and limiting flag. It accepts `ResourcePolicy::detect()` observations and explicitly configured budget values. Additional nonnumeric runtime capability observations are retained as configuration facts. Effective policy values are recorded separately; callers must supply their unit and scope.

Recommendations are upper bounds calculated only from known, comparable constraints. A partial upper bound does not establish that an upload or job can run. Missing multipart framing leaves the exact request file payload unknown. Unknown other-site mail traffic prevents a usable hourly notification allocation. PHP allocation memory, native address space, native process RSS and account resident memory remain separate quantities. An unbounded PHP setting does not establish available hosting capacity.

The catalog includes SiteGround GrowBig documentation references dated 2026-11-08 with source URLs: a 768 MiB process resident-memory reference; soft CPU thresholds of 2,000 seconds/hour, 20,000/day and 600,000/calendar month; 400,000 total account inodes; 400 mail recipients/hour; and a 30-minute cron interval reference. Each is explicitly `reference_only`, unconfirmed for the site, and excluded from every operative recommendation. Confirm the current plan, units, other account use and provider policy before entering allocations. The CPU ledger uses a conservative rolling 31 days independently of the provider calendar-month reference.

Application policy also appears explicitly: canonical JSON records have a 1 MiB format/read ceiling, and work reserves use at least 20% headroom with additional measured variation where appropriate. These limits describe the implementation and are not hosting entitlements. Unknown quota observations remain unknown; filesystem free space does not establish account quota.

`MailCapacityGuard` checks a complete estimated MIME message before an hourly recipient token is consumed:

```php
$guard = new MailCapacityGuard($budgets, $policy->phpHeadroom());
if (!$guard->messageAllowed($attachedOriginalBytes, $estimatedWireBytes)) {
    return false;
}
return $policy->consumeMail($coordinatorStore, $recipient);
```

`attachmentAllowed($originalBytes, $encodedBytes)` is an early check before copying a private original. The complete-message check is still required afterward. Both use explicit message, framing, assembly and memory-multiplier allocations; unknown allocations defer sending. OTP and data-only reports use zero attached original bytes, irrespective of the retained upload's size. Attachments exceeding their calculated capacity are omitted with explanatory text; explicitly selected OTP links may still be included. A missing complete-message allowance defers the email rather than treating capacity as unlimited.

`estimatedMessageBytes($subject, $body, $actualAttachmentSizes, $overhead)` includes a conservative fourfold text transfer/folding bound, MIME base64 plus CRLF attachment encoding, and one caller-supplied header/framing allowance. The notification manager additionally reserves a small final-eligibility explanation. The guard checks `(actual original bytes + estimated complete message bytes) × configured assembly multiplier` against both the current PHP headroom and configured assembly allocation. The multiplier and framing allowance must cover the installed mail transport and filters; they are not inferred hosting facts. The final WordPress mail adapter restores the intended recipient, plain text and authorized originals so unrelated filters cannot silently add file copies or HTML embeds.

Operational counters may expire; retained originals, scan records and submissions never expire automatically. Receiving transport acceptance is not proof of email delivery. Exactly-once email delivery cannot be guaranteed across a process crash after transport acceptance but before persisting the acknowledgment.

Run `php tests/resource-constraints.php` and `php tests/notifications.php` for focused diagnostics, mail assembly, OTP, race and asset-policy regression checks.

## Account scanner admission and maintenance capacity

Every local or remote scan first acquires `ResourcePolicy::acquireDispatch()`. Its `DispatchLease` holds one account-wide nonblocking OS lock across preparation, hashing/copying, native or remote work, process termination/reaping, final verification and accounting. `release()` is idempotent; destruction/process exit releases the descriptor. A deadline never releases a live slot. Coordination must be bound; an unavailable slot raises `ResourceDeferred` with a reason and retry time. The caller must retain the original, deny publication and schedule bounded retry rather than recording a scanner verdict.

`beginScan()` reserves scan work; `beginNative()` reserves maintenance work. Both use the shared account CPU ledger and `finishNative()` completion. Scans can consume at most80% of each explicitly allocated rolling CPU window; the remaining20% is implementation policy reserved for maintenance. Running submitted scans from trusted cron does not promote their work class. Total maintenance and scan CPU must still fit the original allocation.

Scans also share a fixed-size global pacing record. The rate is the minimum of scan-allocated CPU divided by each window duration. Admission charges the larger CPU/wall request, including its bounded work envelope, and reserves the next eligible time. Successful completion refunds the estimate to measured total preparation/child/finalization CPU, with a one-second policy floor. Unknown usage retains the full charge. Prior pacing debt remains; no per-IP or per-submitter maps grow with traffic. Remote scans require the same explicit work-window allocations and conservatively charge their service wall envelope because remote CPU is not observable on the website.

Native address-space limits require both confirmed process and account resident-memory allocations, and cannot exceed80% of their smaller value. Historical RSS measurements remain useful diagnostics but do not authorize a larger AS limit for arbitrary future inputs. This is deliberately conservative; installations needing larger address mappings require a separately verified runtime RSS enforcement design.

CPU snapshots use bounded packed total/scan buckets. Legacy unclassified CPU conservatively counts against the scan pool. Resource pressure changes no admission counters until work is actually admitted. Integer byte parsing is exact and checked before multiplication; unsupported CPU magnitudes and nonfinite numeric allocations are rejected.

`ResourcePolicy` accepts a third optional constructor callable for its CPU-ledger clock. This is dependency injection for isolated fixtures; no stored setting or request value disables pacing. `withDeadline($absoluteMonotonicSeconds, $callback)` scopes a batch deadline and restores the previous context in `finally`. Native and remote wall allowances are reduced by the remaining monotonic time, then retain the configured cleanup reserve. Nested contexts may shorten but cannot extend their parent deadline. Insufficient remaining time raises `ResourceDeferred('deadline_exhausted', ...)`.
