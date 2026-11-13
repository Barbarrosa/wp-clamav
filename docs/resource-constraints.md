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
