<?php
declare(strict_types=1);

namespace WPClamAV\Notifications;

use WPClamAV\Resources\MailCapacityGuard;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Plain-text notifications and recipient-verified review access, without a mail framework. */
final class NotificationManager
{
    private array $rules;
    private \Closure $mailer;
    private \Closure $capacity;
    private \Closure $clock;
    private string $pepper;

    public function __construct(
        private object $store,
        private NotificationRepository $repository,
        private array $settings,
        callable $mailer,
        callable $capacity,
        private string $privateTempDirectory,
        ?callable $clock = null
    ) {
        $this->rules = self::validateRules(array_key_exists('notification_rules', $settings)
            ? $settings['notification_rules'] : self::defaultRules((string) ($settings['admin_email'] ?? '')));
        $this->mailer = \Closure::fromCallable($mailer);
        $this->capacity = \Closure::fromCallable($capacity);
        $this->clock = $clock === null ? static fn(): int => time() : \Closure::fromCallable($clock);
        $this->pepper = (string) ($settings['otp_pepper'] ?? (function_exists('wp_salt') ? wp_salt('auth') : ''));
    }

    public static function defaultRules(string $adminEmail): array
    {
        if (!self::validEmail($adminEmail)) {
            return [];
        }
        return [[
            'id' => 'administrator-results', 'enabled' => true,
            'events' => ['detected', 'held', 'failed'], 'form_ids' => [],
            'recipients' => [$adminEmail], 'attach_original' => false, 'include_download_link' => false,
            'subject' => '[Upload scan: {status}] {name}',
            'body' => "Result: {status}\nFile: {name}\nBytes: {size}\nSHA-256: {sha256}\nFindings: {findings}\nReasons: {reasons}\nCoverage: {coverage}\nProfile: {profile}\nEngine: {engine_version}\nDatabase: {generation_id}\nTime: {timestamp}\nReceipt: {receipt_id}\nEvent: {event_id}",
        ]];
    }

    private static function validEmail(string $email): bool
    {
        return !str_contains($email, "\r") && !str_contains($email, "\n")
            && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validateRules(array $rules): array
    {
        if (count($rules) > 100) {
            throw new InvalidArgumentException('At most 100 notification rules are supported.');
        }
        $result = [];
        $seen = [];
        $placeholders = ['status', 'name', 'size', 'sha256', 'findings', 'reasons', 'engine_version',
            'generation_id', 'profile', 'coverage', 'timestamp', 'receipt_id', 'event_id', 'download_link'];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                throw new InvalidArgumentException('Notification rules must be objects.');
            }
            $id = (string) ($rule['id'] ?? '');
            if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $id) !== 1 || isset($seen[$id])) {
                throw new InvalidArgumentException('Notification rule identifiers must be unique.');
            }
            $seen[$id] = true;
            $events = array_values(array_unique((array) ($rule['events'] ?? [])));
            if (array_diff($events, ['clean', 'detected', 'held', 'failed']) !== []) {
                throw new InvalidArgumentException('Unknown notification event.');
            }
            $recipients = [];
            foreach ((array) ($rule['recipients'] ?? []) as $email) {
                if (!is_string($email) || !self::validEmail(trim($email))) {
                    throw new InvalidArgumentException('Notification recipients must be explicit email addresses.');
                }
                $recipients[strtolower(trim($email))] = trim($email);
            }
            if (count($recipients) > 100) {
                throw new InvalidArgumentException('At most 100 recipients are supported per rule.');
            }
            $formIds = [];
            foreach ((array) ($rule['form_ids'] ?? []) as $formId) {
                if (filter_var($formId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    throw new InvalidArgumentException('Invalid notification form identifier.');
                }
                $formIds[] = (int) $formId;
            }
            $subject = (string) ($rule['subject'] ?? '[Upload scan: {status}] {name}');
            $body = (string) ($rule['body'] ?? "Result: {status}\nFile: {name}\nReasons: {reasons}");
            if (strpbrk($subject, "\r\n\0") !== false || strlen($subject) > 512 || strlen($body) > 65536 || str_contains($body, "\0")) {
                throw new InvalidArgumentException('Notification template is too large or contains invalid header characters.');
            }
            preg_match_all('/\{([A-Za-z0-9_]+)\}/', $subject . $body, $matches);
            if (array_diff($matches[1], $placeholders) !== []) {
                throw new InvalidArgumentException('Unknown notification placeholder.');
            }
            $result[] = [
                'id' => $id, 'enabled' => (bool) ($rule['enabled'] ?? true), 'events' => $events,
                'form_ids' => array_values(array_unique($formIds)), 'recipients' => array_values($recipients),
                'subject' => $subject, 'body' => $body,
                'attach_original' => (bool) ($rule['attach_original'] ?? false),
                'include_download_link' => (bool) ($rule['include_download_link'] ?? false),
            ];
        }
        return $result;
    }

    private function now(): int { return (int) ($this->clock)(); }

    private function setting(string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, (int) ($this->settings[$key] ?? $default)));
    }

    private function eventId(array $event): string
    {
        return (string) ($event['id'] ?? $event['event_id'] ?? '');
    }

    private function readEvent(string $id): array
    {
        $event = $this->store->event($id);
        if (!is_array($event) || $this->eventId($event) !== $id) {
            throw new RuntimeException('Scan event is missing.');
        }
        return $event;
    }

    private function matches(array $rule, array $event, array $receipt): bool
    {
        $formId = (int) ($receipt['context']['form_id'] ?? $event['context']['form_id'] ?? 0);
        return $rule['enabled'] && in_array((string) ($event['status'] ?? 'failed'), $rule['events'], true)
            && ($rule['form_ids'] === [] || in_array($formId, $rule['form_ids'], true));
    }

    public function onEvent(array $event): void
    {
        $id = $this->eventId($event);
        $event = $this->readEvent($id);
        $uploadId = (string) ($event['upload_id'] ?? '');
        $receipt = $this->store->get($uploadId);
        $latest = $this->store->latestEvent($uploadId);
        if ($latest !== null && $this->eventId($latest) === $id) {
            $this->repository->revokeForUpload($uploadId, $id, $this->now());
        }
        foreach ($this->rules as $rule) {
            if (!$this->matches($rule, $event, $receipt)) {
                continue;
            }
            foreach ($rule['recipients'] as $recipient) {
                $this->enqueueScan($event, $rule, $recipient, 'automatic', '', '');
            }
        }
    }

    private function enqueueScan(array $event, array $rule, string $recipient, string $requestId, string $actor, string $reason): string
    {
        $now = $this->now();
        return $this->repository->enqueue([
            'id' => bin2hex(random_bytes(16)), 'kind' => 'scan', 'created_at' => $now, 'next_at' => $now,
            'delivery_key' => hash('sha256', implode('|', [$this->eventId($event), $rule['id'], strtolower($recipient), $requestId])),
            'payload' => ['event_id' => $this->eventId($event), 'recipient' => $recipient, 'rule' => $rule,
                'request_id' => $requestId, 'actor' => $actor, 'reason' => $reason],
        ]);
    }

    /** Explicit resend has a new audited request ID; it never changes a scan event. */
    public function resend(string $eventId, string $ruleId, string $actor, string $reason): array
    {
        if (trim($actor) === '' || trim($reason) === '') {
            throw new InvalidArgumentException('Resending requires an actor and reason.');
        }
        $event = $this->readEvent($eventId);
        $receipt = $this->store->get((string) $event['upload_id']);
        $rule = $this->rule($ruleId);
        if ($rule === null || !$this->matches($rule, $event, $receipt)) {
            throw new InvalidArgumentException('The notification rule does not match this event.');
        }
        $requestId = 'manual-' . bin2hex(random_bytes(16));
        return array_map(fn(string $recipient): string => $this->enqueueScan($event, $rule, $recipient, $requestId,
            substr($actor, 0, 256), substr($reason, 0, 2000)), $rule['recipients']);
    }

    private function rule(string $id): ?array
    {
        foreach ($this->rules as $rule) {
            if ($rule['id'] === $id) {
                return $rule;
            }
        }
        return null;
    }

    private function allowedRecipient(array $rule, string $recipient): bool
    {
        return in_array(strtolower($recipient), array_map('strtolower', $rule['recipients']), true);
    }

    private function eligible(array $event): bool
    {
        try {
            $id = (string) ($event['upload_id'] ?? '');
            return AssetPolicy::eligible($event, $this->store->get($id), $this->store->latestEvent($id));
        } catch (Throwable) {
            return false;
        }
    }

    private function textList(mixed $values): string
    {
        if (!is_array($values)) {
            return substr((string) $values, 0, 8192);
        }
        $items = [];
        foreach (array_slice($values, 0, 100) as $value) {
            $items[] = substr(is_scalar($value) ? (string) $value : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE), 0, 512);
        }
        if (count($values) > 100) {
            $items[] = '(Additional entries remain in the scan record.)';
        }
        return $items === [] ? 'none' : implode('; ', $items);
    }

    private function fileName(array $event): string
    {
        $name = basename(str_replace('\\', '/', (string) ($event['name'] ?? 'upload')));
        return substr(str_replace(["\r", "\n", "\0"], '_', $name), 0, 240) ?: 'upload';
    }

    private function render(array $event, array $rule, string $link): array
    {
        $values = [
            '{status}' => (string) ($event['status'] ?? 'failed'), '{name}' => $this->fileName($event),
            '{size}' => (string) ($event['size'] ?? 'unknown'), '{sha256}' => (string) ($event['sha256'] ?? 'unknown'),
            '{findings}' => $this->textList($event['findings'] ?? []), '{reasons}' => $this->textList($event['reason_codes'] ?? []),
            '{engine_version}' => (string) ($event['engine_version'] ?? 'unknown'),
            '{generation_id}' => (string) ($event['generation_id'] ?? 'unknown'),
            '{profile}' => (string) ($event['effective_profile'] ?? $event['profile'] ?? 'unknown'),
            '{coverage}' => ($event['applicability_proven'] ?? false) === true ? 'eligible' : 'not established; review required',
            '{timestamp}' => gmdate('c', (int) ($event['created_at'] ?? 0)),
            '{receipt_id}' => (string) ($event['upload_id'] ?? ''), '{event_id}' => $this->eventId($event),
            '{download_link}' => $link,
        ];
        $subject = substr(str_replace(["\r", "\n", "\0"], ' ', $this->substitute($rule['subject'], $values, 512)), 0, 200);
        $body = str_replace("\0", '', $this->substitute($rule['body'], $values, 65536));
        if ($link !== '' && !str_contains($body, $link)) {
            $body .= "\n\nRecipient-verified review download: " . $link;
        }
        if (in_array((string) ($event['status'] ?? ''), ['detected', 'held'], true)) {
            $body .= "\n\nThis file is " . $event['status'] . "; sharing it for review is not approval to publish or execute it.";
        }
        return [$subject, $body];
    }

    private function substitute(string $template, array $values, int $limit): string
    {
        $parts = preg_split('/(\{[A-Za-z0-9_]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) { throw new RuntimeException('Unable to render the result template.'); }
        $result = '';
        foreach ($parts as $part) {
            $value = $values[$part] ?? $part;
            $remaining = $limit - strlen($result);
            if (strlen($value) > $remaining) {
                return $result . substr($value, 0, max(0, $remaining))
                    . "\n[Email report shortened to its format limit; the complete canonical scan record is retained.]";
            }
            $result .= $value;
        }
        return $result;
    }

    private function mailOverhead(): int
    {
        $value = $this->settings['budgets']['mail_message_overhead_bytes'] ?? 4096;
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > intdiv(PHP_INT_MAX, 4)) {
            throw new RuntimeException('The configured mail overhead cannot be represented safely.');
        }
        return (int) ceil((float) $value);
    }

    public static function encodedAttachmentBytes(int $bytes): int
    {
        if ($bytes < 0 || $bytes > intdiv(PHP_INT_MAX - 8, 4)) {
            throw new InvalidArgumentException('Attachment size cannot be represented.');
        }
        $base64 = 4 * intdiv($bytes + 2, 3);
        return $base64 + 2 * intdiv($base64 + 75, 76);
    }

    private function copyAttachment(array $event): string
    {
        $directory = realpath($this->privateTempDirectory);
        if ($directory === false || !is_dir($directory) || is_link($this->privateTempDirectory)) {
            throw new RuntimeException('Private notification scratch storage is unavailable.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . '.mail-' . bin2hex(random_bytes(24));
        $output = fopen($path, 'x+b');
        if ($output === false) {
            throw new RuntimeException('Cannot create a private attachment copy.');
        }
        chmod($path, 0600);
        $input = null;
        try {
            $input = $this->verifiedStream($event);
            $copied = stream_copy_to_stream($input, $output, (int) $event['size']);
            if ($copied !== (int) $event['size'] || !fflush($output)
                || !hash_equals((string) $event['sha256'], (string) hash_file('sha256', $path))) {
                throw new RuntimeException('Attachment preservation failed.');
            }
            fclose($output);
            fclose($input);
            return $path;
        } catch (Throwable $error) {
            if (is_resource($output)) { fclose($output); }
            if (is_resource($input)) { fclose($input); }
            @unlink($path);
            throw $error;
        }
    }

    /** Verify before serving any bytes; the local store supplies a seekable, immutable stream. */
    private function verifiedStream(array $event)
    {
        $input = $this->store->open((string) $event['upload_id']);
        if (!is_resource($input)) {
            throw new RuntimeException('Stored original is unavailable.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($input)) {
                $data = fread($input, 65536);
                if ($data === false || ($data === '' && !feof($input))) {
                    throw new RuntimeException('Unable to verify the stored original.');
                }
                $bytes += strlen($data);
                if ($bytes > (int) $event['size']) {
                    throw new RuntimeException('The stored original changed.');
                }
                hash_update($hash, $data);
            }
            if ($bytes !== (int) $event['size'] || !hash_equals((string) $event['sha256'], hash_final($hash)) || !rewind($input)) {
                throw new RuntimeException('The stored original failed its integrity check.');
            }
            return $input;
        } catch (Throwable $error) {
            fclose($input);
            throw $error;
        }
    }

    private function ruleFingerprint(array $rule): string
    {
        return hash('sha256', json_encode([$this->settings['notification_rule_epoch'] ?? 1, $rule], JSON_THROW_ON_ERROR));
    }

    private function issueGrant(array $row, array $event, array $rule, string $recipient): array
    {
        $url = (string) ($this->settings['download_url'] ?? '');
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || !isset($parts['host']) || strpbrk($url, "\r\n\0") !== false || strlen($this->pepper) < 32) {
            throw new RuntimeException('Secure recipient download access is not configured.');
        }
        $now = $this->now();
        // A replacement worker must never have its grant revoked by an expired worker.
        $id = substr(hash('sha256', 'grant|' . $row['id'] . '|' . $row['lease_token']), 0, 32);
        $secret = bin2hex(random_bytes(32));
        $grant = [
            'id' => $id, 'upload_id' => (string) $event['upload_id'], 'event_id' => $this->eventId($event),
            'recipient' => $recipient, 'sha256' => (string) $event['sha256'], 'rule_id' => $rule['id'],
            'rule_fingerprint' => $this->ruleFingerprint($rule), 'token_hash' => hash('sha256', $secret),
            'expires_at' => $now + $this->setting('invitation_lifetime', 86400, 300, 604800),
            'revoked_at' => 0, 'revision' => 0, 'issued_at' => $now,
            'challenge_sequence' => 0, 'challenge_hash' => '', 'challenge_expires_at' => 0,
            'challenge_attempts' => 0, 'challenge_state' => 'none',
            'request_window_start' => $now, 'request_count' => 0,
            'session_hash' => '', 'session_expires_at' => 0,
        ];
        if (!$this->repository->createGrant($grant)) {
            $old = $this->repository->grant($id);
            if ($old === null || !$this->repository->updateGrant($grant, (int) $old['revision'])) {
                throw new RuntimeException('Download grant was concurrently changed.');
            }
        }
        $separator = str_contains($url, '?') ? '&' : '?';
        return [$id, $url . $separator . http_build_query(['grant' => $id, 'token' => $secret], '', '&', PHP_QUERY_RFC3986)];
    }

    private function revokeGrant(string $id): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $grant = $this->repository->grant($id);
            if ($grant === null || (int) $grant['revoked_at'] !== 0) { return; }
            $revision = (int) $grant['revision'];
            $grant['revoked_at'] = $this->now();
            if ($this->repository->updateGrant($grant, $revision)) { return; }
        }
    }

    public function runQueue(int $limit = 10): array
    {
        $result = ['accepted' => 0, 'deferred' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($this->repository->claim($this->now(), $this->setting('mail_lease_seconds', 120, 30, 1800), $limit) as $row) {
            try {
                $state = $row['kind'] === 'otp' ? $this->deliverOtp($row) : $this->deliverScan($row);
                ++$result[$state];
            } catch (Throwable) {
                $this->retry($row, 'Notification processing failed; no acceptance was recorded.', false);
                ++$result['deferred'];
            }
        }
        return $result;
    }

    public function status(): array { return $this->repository->summary($this->now()); }

    private function finish(array $row, string $state, string $error = '', bool $attempted = false): void
    {
        $this->repository->finish($row['id'], $row['lease_token'], $state, $this->now(), $this->now(), $error, $attempted);
    }

    private function retry(array $row, string $reason, bool $attempted): string
    {
        $attempts = (int) $row['attempts'] + ($attempted ? 1 : 0);
        $failed = $attempts >= $this->setting('max_mail_attempts', 4, 1, 20);
        $delay = $this->setting('mail_retry_seconds', 300, 30, 86400) * (2 ** min(6, $attempts));
        $this->repository->finish($row['id'], $row['lease_token'], $failed ? 'failed' : 'retry', $this->now(),
            $this->now() + $delay, $reason, $attempted);
        return $failed ? 'failed' : 'deferred';
    }

    private function send(array $message): bool
    {
        try { return ($this->mailer)($message) === true; }
        catch (Throwable) { return false; }
    }

    /** A lease protects transport side effects as well as conditional state updates. */
    private function ownsLiveLease(array $row): bool
    {
        $current = $this->repository->delivery((string) $row['id']);
        return $current !== null && $current['state'] === 'sending'
            && (int) $current['lease_until'] > $this->now()
            && hash_equals((string) ($current['lease_token'] ?? ''), (string) ($row['lease_token'] ?? ''));
    }

    private function deliverScan(array $row): string
    {
        $payload = $row['payload'];
        $event = $this->readEvent((string) $payload['event_id']);
        $receipt = $this->store->get((string) $event['upload_id']);
        $recipient = (string) $payload['recipient'];
        $rule = $this->rule((string) $payload['rule']['id']);
        if ($rule === null || !$this->matches($rule, $event, $receipt) || !$this->allowedRecipient($rule, $recipient)) {
            $this->finish($row, 'cancelled', 'Notification rule or recipient is no longer enabled.');
            return 'cancelled';
        }
        $currentRule = $rule;
        // A rule change can restrict queued assets, but cannot silently upgrade old deliveries.
        $rule['attach_original'] = $rule['attach_original'] && $payload['rule']['attach_original'];
        $rule['include_download_link'] = $rule['include_download_link'] && $payload['rule']['include_download_link'];
        $rule['subject'] = $payload['rule']['subject'];
        $rule['body'] = $payload['rule']['body'];
        $eligible = $this->eligible($event);
        $attachments = [];
        $grantId = '';
        $link = '';
        $assetNote = '';
        try {
            if ($eligible && $rule['attach_original']) {
                $size = (int) $event['size'];
                $encoded = self::encodedAttachmentBytes($size);
                if (($this->capacity)('attachment', $recipient, $size, $encoded) === true) {
                    try {
                        $attachments[] = ['path' => $this->copyAttachment($event), 'name' => $this->fileName($event)];
                    } catch (Throwable) {
                        $eligible = false;
                        $assetNote = 'Original file sharing was withheld because its integrity or private storage could not be verified.';
                    }
                } else {
                    $assetNote = 'Original attachment omitted because the calculated delivery capacity does not permit it.';
                }
            }
            if ($eligible && $rule['include_download_link']) {
                try {
                    if ($attachments === []) {
                        $verified = $this->verifiedStream($event);
                        fclose($verified);
                    }
                    [$grantId, $link] = $this->issueGrant($row, $event, $currentRule, $recipient);
                } catch (Throwable) {
                    $assetNote .= "\nRecipient-verified download access is unavailable.";
                }
            }
            // Recheck after I/O and immediately before the mail boundary.
            if (!$eligible || !$this->eligible($event)) {
                foreach ($attachments as $attachment) { @unlink($attachment['path']); }
                $attachments = [];
                if ($grantId !== '') { $this->revokeGrant($grantId); }
                $grantId = $link = '';
                $eligible = false;
            }
            try {
                [$subject, $body] = $this->render($event, $rule, $link);
            } catch (Throwable) {
                foreach ($attachments as $attachment) { @unlink($attachment['path']); }
                $attachments = [];
                if ($grantId !== '') { $this->revokeGrant($grantId); }
                $grantId = $link = '';
                $eligible = false;
                $subject = 'Upload scan result';
                $body = 'Scan event ' . $this->eventId($event) . ': ' . (string) ($event['status'] ?? 'failed')
                    . ". The configured result could not be fully rendered; review the canonical scan record. No original is included.";
            }
            if ($assetNote !== '') { $body .= "\n\n" . trim($assetNote); }
            $attachmentSizes = array_fill(0, count($attachments), (int) ($event['size'] ?? 0));
            $attachedOriginalBytes = array_sum($attachmentSizes);
            // Reserve enough text space for the final eligibility recheck's explanatory note too.
            $encodedBytes = MailCapacityGuard::estimatedMessageBytes($subject, $body . str_repeat(' ', 256), $attachmentSizes, $this->mailOverhead());
            if (($this->capacity)('mail', $recipient, $attachedOriginalBytes, $encodedBytes) !== true) {
                if ($grantId !== '') { $this->revokeGrant($grantId); }
                return $this->retry($row, 'Waiting for calculated notification capacity.', false);
            }
            // Capacity reservation may wait on another worker; do not mail a now-superseded original.
            if ($eligible && !$this->eligible($event)) {
                foreach ($attachments as $attachment) { @unlink($attachment['path']); }
                $attachments = [];
                if ($grantId !== '') { $this->revokeGrant($grantId); }
                $grantId = $link = '';
                $eligible = false;
                [$subject, $body] = $this->render($event, $rule, '');
                $body .= "\n\nOriginal sharing withheld because this event is no longer the authorized current attempt.";
            }
            if (!$this->ownsLiveLease($row)) {
                if ($grantId !== '') { $this->revokeGrant($grantId); }
                return 'deferred';
            }
            $message = ['to' => $recipient, 'subject' => $subject, 'body' => $body,
                'headers' => ['Content-Type: text/plain; charset=UTF-8'], 'attachments' => $attachments,
                'event_id' => $this->eventId($event), 'upload_id' => (string) $event['upload_id'],
                'kind' => 'scan', 'asset_eligible' => $eligible];
            $accepted = $this->send($message);
            if (!$accepted) {
                if ($grantId !== '') { $this->revokeGrant($grantId); }
                return $this->retry($row, 'Mail transport did not accept the notification.', true);
            }
            $this->finish($row, 'accepted', '', true);
            return 'accepted';
        } catch (Throwable $error) {
            if ($grantId !== '') { $this->revokeGrant($grantId); }
            throw $error;
        } finally {
            foreach ($attachments as $attachment) { @unlink($attachment['path']); }
        }
    }

    private function usableGrant(string $id, ?string $secret = null): ?array
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) { return null; }
        try {
            $grant = $this->repository->grant($id);
            if ($grant === null || (int) $grant['revoked_at'] !== 0 || (int) $grant['expires_at'] <= $this->now()) { return null; }
            if ($secret !== null && (preg_match('/\A[0-9a-f]{64}\z/D', $secret) !== 1
                || !hash_equals((string) $grant['token_hash'], hash('sha256', $secret)))) { return null; }
            $rule = $this->rule((string) $grant['rule_id']);
            $event = $this->readEvent((string) $grant['event_id']);
            if ($rule === null || !$rule['enabled'] || !$rule['include_download_link']
                || !$this->allowedRecipient($rule, (string) $grant['recipient'])
                || !hash_equals($this->ruleFingerprint($rule), (string) $grant['rule_fingerprint'])
                || !$this->eligible($event) || !hash_equals((string) $grant['sha256'], (string) $event['sha256'])) {
                $this->revokeGrant($id);
                return null;
            }
            return $grant;
        } catch (Throwable) {
            return null;
        }
    }

    /** Landing metadata only: does not send mail, consume access, or read original bytes. */
    public function invitation(string $id, string $secret): ?array
    {
        $grant = $this->usableGrant($id, $secret);
        if ($grant === null) { return null; }
        [$local, $domain] = explode('@', (string) $grant['recipient'], 2);
        return ['recipient' => substr($local, 0, 1) . '***@' . $domain, 'expires_at' => $grant['expires_at'],
            'grant_id' => $id, 'status' => (string) $this->readEvent((string) $grant['event_id'])['status']];
    }

    private function throttle(string $scope, string $identity, int $limit): bool
    {
        return $this->repository->consumeThrottle(hash_hmac('sha256', $scope . '|' . strtolower($identity), $this->pepper),
            $this->now(), $limit, $this->setting('otp_request_window', 900, 60, 86400));
    }

    public function requestOtp(string $id, string $secret, string $requester = ''): bool
    {
        if (strlen($this->pepper) < 32) { return false; }
        for ($i = 0; $i < 3; ++$i) {
            $grant = $this->usableGrant($id, $secret);
            if ($grant === null) { return false; }
            $now = $this->now();
            if ($grant['challenge_state'] !== 'queued') {
                if ((int) $grant['request_window_start'] + $this->setting('otp_request_window', 900, 60, 86400) <= $now) {
                    $grant['request_window_start'] = $now;
                    $grant['request_count'] = 0;
                }
                if ((int) $grant['request_count'] >= $this->setting('otp_max_requests', 3, 1, 10)) { return false; }
                if (!$this->throttle('otp-recipient', (string) $grant['recipient'], $this->setting('otp_max_requests', 3, 1, 10))
                    || ($requester !== '' && !$this->throttle('otp-requester', $requester, $this->setting('otp_max_requests_per_requester', 10, 1, 100)))) {
                    return false;
                }
                $revision = (int) $grant['revision'];
                ++$grant['request_count'];
                ++$grant['challenge_sequence'];
                $grant['challenge_state'] = 'queued';
                $grant['challenge_hash'] = $grant['session_hash'] = '';
                $grant['challenge_expires_at'] = $grant['challenge_attempts'] = $grant['session_expires_at'] = 0;
                if (!$this->repository->updateGrant($grant, $revision)) { continue; }
            }
            $this->repository->enqueue([
                'id' => bin2hex(random_bytes(16)), 'kind' => 'otp', 'created_at' => $now, 'next_at' => $now,
                'delivery_key' => hash('sha256', 'otp|' . $id . '|' . $grant['challenge_sequence']),
                'payload' => ['grant_id' => $id, 'sequence' => (int) $grant['challenge_sequence']],
            ]);
            return true;
        }
        return false;
    }

    private function codeHash(string $code, array $grant): string
    {
        return hash_hmac('sha256', $grant['id'] . '|' . $grant['challenge_sequence'] . '|' . $code, $this->pepper);
    }

    private function deliverOtp(array $row): string
    {
        $grant = $this->usableGrant((string) $row['payload']['grant_id']);
        if ($grant === null || (int) $grant['challenge_sequence'] !== (int) $row['payload']['sequence']
            || !in_array($grant['challenge_state'], ['queued', 'sending'], true)) {
            $this->finish($row, 'cancelled', 'Verification request is obsolete or unavailable.');
            return 'cancelled';
        }
        $recipient = (string) $grant['recipient'];
        $otpBytes = MailCapacityGuard::estimatedMessageBytes('Your upload-review verification code', str_repeat(' ', 512), [], $this->mailOverhead());
        if (($this->capacity)('mail', $recipient, 0, $otpBytes) !== true) {
            return $this->retry($row, 'Waiting for calculated verification-mail capacity.', false);
        }
        $grant = $this->usableGrant((string) $row['payload']['grant_id']);
        if ($grant === null || (int) $grant['challenge_sequence'] !== (int) $row['payload']['sequence']) {
            $this->finish($row, 'cancelled', 'Verification became unavailable while reserving delivery capacity.');
            return 'cancelled';
        }
        if (!$this->ownsLiveLease($row)) { return 'deferred'; }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $revision = (int) $grant['revision'];
        $grant['challenge_hash'] = $this->codeHash($code, $grant);
        $grant['challenge_expires_at'] = min((int) $grant['expires_at'], $this->now() + $this->setting('otp_lifetime', 600, 60, 1800));
        $grant['challenge_attempts'] = 0;
        $grant['challenge_state'] = 'sending';
        if (!$this->repository->updateGrant($grant, $revision)) {
            return $this->retry($row, 'Verification request changed concurrently.', false);
        }
        // No original attachment or link is ever included in the OTP message itself.
        $message = ['to' => $recipient, 'subject' => 'Your upload-review verification code',
            'body' => 'Verification code: ' . $code . "\n\nThis code is valid for " . (int) ceil(($grant['challenge_expires_at'] - $this->now()) / 60)
                . " minutes. Enter it in the review page you opened. If you did not request it, ignore this email.",
            'headers' => ['Content-Type: text/plain; charset=UTF-8'], 'attachments' => [],
            'event_id' => $grant['event_id'], 'upload_id' => $grant['upload_id'], 'kind' => 'otp', 'asset_eligible' => false];
        if (!$this->ownsLiveLease($row)) { return 'deferred'; }
        $accepted = $this->send($message);
        $fresh = $this->repository->grant($grant['id']);
        if ($fresh !== null && (int) $fresh['challenge_sequence'] === (int) $grant['challenge_sequence']) {
            $revision = (int) $fresh['revision'];
            $fresh['challenge_state'] = $accepted ? 'sent' : 'queued';
            if (!$accepted) { $fresh['challenge_hash'] = ''; $fresh['challenge_expires_at'] = 0; }
            $this->repository->updateGrant($fresh, $revision);
        }
        if (!$accepted) { return $this->retry($row, 'Mail transport did not accept the verification code.', true); }
        $this->finish($row, 'accepted', '', true);
        return 'accepted';
    }

    public function verifyOtp(string $id, string $secret, string $code, string $requester = ''): ?string
    {
        if (strlen($this->pepper) < 32) { return null; }
        $initial = $this->usableGrant($id, $secret);
        if ($initial === null || ($requester !== '' && !$this->throttle('otp-verification-requester', $requester,
            $this->setting('otp_max_verifications_per_requester', 30, 1, 100)))) { return null; }
        for ($i = 0; $i < 3; ++$i) {
            $grant = $this->usableGrant($id, $secret);
            if ($grant === null || !in_array($grant['challenge_state'], ['sent', 'sending'], true)
                || (int) $grant['challenge_expires_at'] <= $this->now()
                || (int) $grant['challenge_attempts'] >= $this->setting('otp_max_attempts', 5, 1, 10)) { return null; }
            $revision = (int) $grant['revision'];
            ++$grant['challenge_attempts'];
            $valid = preg_match('/\A[0-9]{6}\z/D', $code) === 1
                && hash_equals((string) $grant['challenge_hash'], $this->codeHash($code, $grant));
            $session = null;
            if ($valid) {
                $session = bin2hex(random_bytes(32));
                $grant['session_hash'] = hash('sha256', $session);
                $grant['session_expires_at'] = min((int) $grant['expires_at'], $this->now() + $this->setting('download_session_lifetime', 900, 60, 3600));
                $grant['challenge_hash'] = '';
                $grant['challenge_state'] = 'used';
            }
            if ($this->repository->updateGrant($grant, $revision)) { return $session; }
        }
        return null;
    }

    /** Metadata-only session check, safe for the landing page's GET request. */
    public function hasDownloadSession(string $id, string $sessionSecret): bool
    {
        $grant = $this->usableGrant($id);
        return $grant !== null && preg_match('/\A[0-9a-f]{64}\z/D', $sessionSecret) === 1
            && (int) $grant['session_expires_at'] > $this->now()
            && hash_equals((string) $grant['session_hash'], hash('sha256', $sessionSecret));
    }

    /** Caller serves this verified stream only in an authenticated POST response. */
    public function openDownload(string $id, string $sessionSecret): array
    {
        $grant = $this->usableGrant($id);
        if ($grant === null || !$this->hasDownloadSession($id, $sessionSecret)) {
            throw new RuntimeException('Download authorization is invalid or expired.');
        }
        $event = $this->readEvent((string) $grant['event_id']);
        $stream = $this->verifiedStream($event);
        if (!$this->eligible($event)) {
            fclose($stream);
            throw new RuntimeException('The file is no longer eligible for sharing.');
        }
        return ['stream' => $stream, 'name' => $this->fileName($event), 'size' => (int) $event['size'],
            'sha256' => (string) $event['sha256'], 'status' => (string) $event['status']];
    }
}
