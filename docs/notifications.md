# Scan notifications and recipient-verified review downloads

Each configured rule selects scan outcomes and optionally Forminator form IDs. It has explicit recipient addresses, a plain-text template, and independent original-attachment and download-link options. Matching rules enqueue **one message per recipient**; no CC/BCC recipient list is disclosed. Recipients are never read from an untrusted upload field.

The initial rule sends result-only `detected`, `held`, and `failed` notices to the site administrator. Clean notices and file sharing are off until configured. All automatic delivery is subject to the site's confirmed mail allocation; an unknown budget leaves notifications queued.

## File-sharing boundary

Original sharing requires a completely preserved, undeleted original, a completed eligible scan event, matching hashes/sizes, and that same event still being the receipt's current attempt. A clean event also requires qualified coverage. A completed held or detected original can be shared only through an explicitly enabled review rule; that is not upload-publication approval.

Failed, incomplete, interrupted, stale, changed, or missing originals never produce original attachments, embeds, or download capabilities. The check applies to preparation, the final mail boundary, resend, OTP requests and download. A new assessment immediately makes older sharing capabilities unusable. Reconciliation of an older event cannot revoke a newer valid grant or give the older event newer permissions.

Attachments are verified private temporary copies removed when the synchronous mail call ends. Their size limit comes from the resource policy, including base64 line wrapping and PHP mail-assembly memory. Unknown or insufficient attachment capacity gives a text-only message or the independently enabled review link; it never silently enables a link.

## Email verification

Review links expose only a landing page. Opening a GET sends no code and reads no original bytes. A nonce-protected POST queues an OTP to the rule's configured recipient. Verification grants a short-lived Secure, HttpOnly, SameSite=Strict browser session; downloading remains a POST and rechecks the current file/event/hash.

Invitation secrets, OTPs and browser-session secrets are stored only as hashes/HMACs. Codes begin their lifetime when dispatched, rather than while waiting for mail capacity. Security-policy defaults are a 24-hour invitation, 10-minute OTP, 15-minute verified browser session, three code requests per recipient/window, and five verification attempts per challenge. These are security settings, not hosting-resource entitlements. Recipient and requester throttles are shared across requests. Requester fingerprints use the direct server peer address and are stored as HMACs; untrusted forwarded-IP headers cannot bypass throttling.

Changing notification rule settings advances an epoch, invalidating previously issued grants even if the same settings are later restored. Expiry or revocation never removes retained originals or scan history.

## Delivery state

The durable outbox deduplicates automatic `(event, rule, recipient)` delivery, leases rows to workers, retries transport failures within a configured attempt bound, and recovers expired worker leases. Manual resend records a new request identifier, administrator and reason; it keeps the original scan-event restrictions.

`accepted` means the mail transport accepted the request. It does not prove inbox delivery. A process failure between sending and recording acceptance can cause a duplicate; there is no exactly-once-delivery claim. Capacity deferral does not consume a transport attempt. OTPs and retries consume the same recipient allocation as other plugin mail.

Templates support only documented placeholders and contain no executable PHP or arbitrary attachment paths. Private filesystem locations are not rendered. Rendering failures fall back to a minimal result-only report. The WordPress mail adapter clears attachments and embeds at its scoped final mail boundary before adding only the manager-authorized copies.

Email attachments create independent copies outside website retention controls. Removing a local original or expiring a link cannot delete those copies.

## Verification

`php tests/notifications.php` checks the outcome/completion matrix, per-recipient rules, retries, leases, OTP and session boundaries, stale-event reconciliation, tampering and capacity behavior.

With a disposable WordPress database, `tests/notifications-database.php` exercises real wpdb SQL. `tests/notifications-wordpress-http.php` additionally uses the running WordPress HTTP server to check real nonces, cookies, GET/POST behavior and revocation. Configure `WP_CLAMAV_WORDPRESS_DIR` and `WP_CLAMAV_HTTP_BASE`; these tests create and clean their own tables and fixture storage, and send no external mail.

The manager revalidates the outbox lease immediately before transport side effects. Replacement workers receive separate grant identities, preventing an expired worker from revoking a newer invitation. This reduces overlapping-worker duplicates; a crash after transport acceptance but before acknowledgment still permits a later repeated email. SMTP acceptance is not delivery confirmation.

Complete-message and PHP assembly checks apply to OTP and result-only reports as well as attachments. Only originals actually attached are charged as original mail bytes; retained originals do not inflate data-only report memory calculations. Text transfer encoding, MIME line folding and a configured header/framing reserve are included. See [resource constraints](resource-constraints.md) for the integration contract and unknown-capacity behavior.
