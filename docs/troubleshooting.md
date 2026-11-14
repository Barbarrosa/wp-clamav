<p><a href="../README.md">WP ClamAV</a> / <a href="README.md">Documentation</a></p>

# Troubleshoot a held upload

Open **Capacity and diagnostics**, then inspect the receipt's latest event in
**Uploads and review**. A hold or capacity refusal preserves the original; it
does not approve publication. Fix the limiting condition before retrying work.

## Start with the symptom

| Symptom or reason | Check | Next step |
| --- | --- | --- |
| Plugin is active, but protection is disabled | **Settings → Enable protection**; initial configuration and staging qualification. | Complete [getting started](getting-started.md). Activation alone does not enable interception. |
| Intake refuses a file or runs out of private capacity | Effective upload/request limits, storage allocation, bytes/inodes, reserves and quota observation age. | Confirm/add capacity or correct the allocation; retain accepted data. See [storage](storage-accounting.md). |
| `resident_allocation_unknown` / `native_limits_unknown` | Confirmed process/account RAM, AS, CPU, work deadline, cleanup and file limits. | Enter measured site allocations using [configuration](configuration.md). An old RSS measurement cannot raise the AS ceiling. |
| `cpu_*_exhausted` / `scan_pacing` | Shared rolling CPU usage and the event's `retry_at`. | Let the bounded queue honor its retry time. Resubmitting or restarting does not create a fresh allowance. |
| `dispatch_busy` / `native_state_busy` | Active scan/update and native process lifetime. | Let the live job finish. Do not delete lock files or create another state directory to bypass admission. |
| `maintenance_pending` | Due update work and its priority record. | Allow maintenance to acquire the shared slot. New scans defer so a flood cannot starve updates. |
| `backend_unavailable` or native startup failure | Executable/certificate/state paths, bundle compatibility and PHP process support. | Repair the verified local installation or configure an authenticated HTTPS backend. |
| Forminator original is `awaiting_submission` or policy-held | Final submission status, trusted receipt membership, file counts/type/framing and qualified vendor version. | Inspect canonical lifecycle holds. Draft, abandoned, spam, pending and preview entries cannot authorize automatic scans. |
| A reduced-profile scan remains held | Original/root type and excluded-capability reasons, including embedded PE. | Request an administrator full scan if its resource envelope fits. A filename or MIME change cannot establish coverage. |
| `automatic_attempts_exhausted` / queue capacity hold | Latest execution errors, configured attempts and pending queue state. | Fix the cause, then use an explicit administrator retry/full scan for review. Originals and narrative remain retained. |
| No automatic scan runs after Forminator submission | Reliable due-event scheduling, queue state, CPU pacing and eligibility. | Check the coalesced dispatch wake and recurring worker; WP-Cron depends on traffic. |

The account coordinator is shared by sites in a network-managed installation.
Other account activity also needs its own capacity; application accounting is not
a kernel quota for the whole hosting account.

## Database or code updates do not complete

| Situation | Expected behavior and action |
| --- | --- |
| Database is stale, missing or unvalidated | The scanner holds/fails work. Check the last update outcome, original-source connectivity, persistent cooldown, certificates and update storage/work allocations. |
| A new generation or code candidate fails validation | The last valid program/database tuple stays active. Diagnose the candidate; do not edit the active pointer or bypass validation. |
| Release verification key is missing | Code installation is unconfigured. Existing native installations can still maintain official databases independently. |
| No signed release is available | A pending CI artifact is not an installable signed channel release. Follow [release promotion](releases.md). |
| Update attempts defer | Check the two-hour minimum interval, upstream cooldown, maintenance admission and total state bytes/inodes. Retained old generations still consume space. |

Database freshness defaults to **48 hours** as policy. Historical test allowances
are fixture inputs, not production recommendations. Native and plugin code use
separate trusted public keys. Obtain the intended public key through a trusted
publisher channel rather than treating an unauthenticated download as a trust root.

## Notification is missing, delayed or has no file

Check the rule's enabled state, selected events/forms, recipients and outbox state.
Each recipient consumes the shared mail allocation. Unknown complete-message or
PHP assembly capacity defers delivery; mail transport acceptance does not prove
inbox delivery.

Attachments and email-verified review links are separate opt-ins. A failed or
incomplete scan has neither. Native limit, parser, encrypted-content and unknown
layer warnings also prohibit assets even alongside a detection. A completed held
or detected event can permit explicitly enabled review when the current original
and event still satisfy [asset eligibility](notifications.md#control-when-files-can-be-shared).

A GET landing link sends no OTP and downloads no bytes. Verify the intended
recipient, nonce-protected POST, code lifetime, session and current event when
diagnosing a review link. A new assessment or changed rule epoch invalidates old
permissions; expired links do not delete originals.

## Collect a useful development report

Record the plugin/PHP/WordPress/Forminator versions, backend/profile, latest reason
codes, relevant effective limits, scheduling state and a sanitized error excerpt.
Include a harmless reproduction and the failing test layer when possible.

Keep original uploads, submitter narrative, credentials, OTPs and private paths out
of public issues. Do not run the disposable test mu-plugin on a live site.
See [development](development.md) for isolated reproduction tools.

Next: [Configuration](configuration.md) · [Scan dispatch](scan-dispatch.md) ·
[Notifications](notifications.md)
