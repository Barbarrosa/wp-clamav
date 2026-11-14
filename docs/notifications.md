<p><a href="../README.md">WP ClamAV</a> / <a href="README.md">Documentation</a></p>

<a name="scan-notifications-and-recipient-verified-review-downloads"></a>

# Notifications and review downloads

Send a separate scan report to each recipient. Enable original attachments or
email-verified review links only where your review process needs them.

> Failed or incomplete scans can send result-only reports. They cannot issue an
> attachment, embed, or download capability.

**On this page:** [Rules](#choose-events-and-recipients) ·
[File sharing](#control-when-files-can-be-shared) ·
[Email verification](#verify-the-recipient-before-download) ·
[Delivery](#understand-delivery-and-retries) · [Testing](#verify-changes)

## Choose events and recipients

Each rule selects outcomes, optional Forminator form IDs, explicit recipient
addresses, and plain-text templates. Matching rules enqueue **one message per
recipient**. No CC/BCC list is disclosed, and upload fields never supply recipients.

| Rule field | Behavior |
| --- | --- |
| `events` | Select `clean`, `detected`, `held`, or `failed`. |
| `form_ids` | Restrict a rule to selected Forminator forms; an empty list adds no form restriction. |
| `recipients` | Explicit addresses; multiple rules and multiple recipients may match an event. |
| `attach_original` | Independently opt into original attachments, subject to eligibility and capacity. |
| `include_download_link` | Independently opt into recipient-verified review links. |
| `subject`, `body` | Plain-text templates using the supported placeholders below. |

The initial administrator rule selects `detected`, `held`, and `failed` and sends
result-only reports. Clean notices and both sharing options start off. An unknown
mail allocation leaves notifications queued until capacity is confirmed.

<details>
<summary>Template reference</summary>

Supported placeholders are `{status}`, `{name}`, `{size}`, `{sha256}`, `{findings}`,
`{reasons}`, `{engine_version}`, `{generation_id}`, `{profile}`, `{coverage}`,
`{timestamp}`, `{receipt_id}`, `{event_id}`, and `{download_link}`.

Templates contain no executable PHP or arbitrary attachment paths. Private
filesystem locations are not rendered. A rendering failure falls back to a
minimal result-only report.

</details>

<a name="file-sharing-boundary"></a>

## Control when files can be shared

All sharing requires a completely preserved, undeleted original, matching hash
and size, and a completed eligible event that is still the receipt's current
attempt. A clean event also requires qualified coverage and applicability.

| Current result | Attachment or review link |
| --- | --- |
| Qualified, completed `clean` | Available only through an explicitly enabled rule. |
| Eligible, completed `detected` or `held` | Available only through an explicitly enabled review rule; this does not approve publication. |
| Failed, incomplete, interrupted, stale, changed, or missing original | Withheld at every sharing boundary. |

Native warnings also withhold assets when the protocol envelope says `completed`
or includes a detection. The denied reason families are
`scan-limit-encryption-or-parser-alert`, `parser-error`, `encrypted-content`, and
`unknown-or-unsupported-layer`. An expansion-limit heuristic does not establish
that every member was scanned. Result-only notifications remain available.

Eligibility is checked during preparation, at the final mail boundary, on resend,
and throughout OTP and download handling. A new assessment immediately invalidates
older sharing capabilities. Reconciliation cannot give an older event newer
permissions or revoke a newer valid grant.

Attachments are verified private temporary copies, removed when the synchronous
mail call ends. Capacity includes base64 line wrapping and PHP mail assembly.
An oversized attachment is omitted with explanatory text. A review link may
remain only if it was independently enabled; the plugin never enables one as an
implicit fallback.

> Email attachments create independent copies. Local deletion, link expiry, or
> moving website storage cannot remove copies already held in mail systems.

<a name="email-verification"></a>

## Verify the recipient before download

1. The recipient opens a review link. Its GET request shows a landing page; it
   sends no code and reads no original bytes.
2. A nonce-protected POST queues an OTP to the rule's configured email address.
3. Verification creates a short-lived browser session with `Secure`, `HttpOnly`,
   and `SameSite=Strict` cookies.
4. Download uses a POST and rechecks the current file, event, and hash.

| Security policy | Default |
| --- | --- |
| Invitation lifetime | 24 hours |
| OTP lifetime | 10 minutes, starting when dispatched |
| Verified browser session | 15 minutes |
| Code requests | 3 per recipient/window |
| Verification attempts | 5 per challenge |

These are security policies, not hosting-resource entitlements. Invitation, OTP,
and browser-session secrets are stored only as hashes or HMACs. Recipient and
requester throttles are shared across requests. Requester fingerprints use the
direct server peer address and are HMACs; untrusted forwarded-IP headers do not
change the throttle identity.

Editing notification rules advances an epoch and invalidates existing grants,
even if the former settings are later restored. Expiry and revocation never
remove retained originals or scan history.

<a name="delivery-state"></a>

## Understand delivery and retries

The durable outbox deduplicates automatic `(event, rule, recipient)` deliveries,
leases rows to workers, retries transport failures within the configured attempt
bound, and recovers expired leases. Manual resend creates a new request with an
administrator and reason; it retains the original event's sharing restrictions.

Capacity deferral does not consume a transport attempt. OTP messages and retries
use the same recipient allocation as other plugin mail. Complete-message and PHP
assembly limits apply to result-only messages and OTPs as well as attachments.
Only originals actually attached count as original mail bytes; retaining a large
file does not inflate a result-only report's memory calculation.

The manager revalidates the lease immediately before transport. Replacement
workers receive separate grant identities, so an expired worker cannot revoke a
newer invitation. The scoped WordPress mail adapter clears attachments and embeds
before adding only manager-authorized copies.

`accepted` means the transport accepted the message, not that it reached an inbox.
A crash after acceptance and before acknowledgment can cause a duplicate.
Exactly-once delivery is not guaranteed.

<a name="verification"></a>

## Verify changes

Run the isolated notification contracts:

```sh
php tests/notifications.php
```

They cover outcomes and completion, per-recipient rules, capacity, leases,
retries, OTP/session boundaries, stale reconciliation, tampering, and asset policy.

For real WordPress integration, use the disposable fixtures documented in
[development](development.md) and [the WordPress test guide](../tests/wordpress/README.md):

| Suite | Coverage |
| --- | --- |
| [`notifications-database.php`](../tests/notifications-database.php) | Actual wpdb SQL. |
| [`notifications-wordpress-http.php`](../tests/notifications-wordpress-http.php) | Real nonces, cookies, GET/POST rules, and revocation. |

Set `WP_CLAMAV_WORDPRESS_DIR` and `WP_CLAMAV_HTTP_BASE`. These suites create and
clean their own test tables and fixture storage and send no external email.

**Related:** [Resource budgets](resource-constraints.md) ·
[Retention and storage](storage-accounting.md) · [Troubleshooting](troubleshooting.md)
