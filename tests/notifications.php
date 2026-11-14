<?php
declare(strict_types=1);

require_once __DIR__ . '/notifications-fixtures.php';

use WPClamAV\Notifications\AssetPolicy;
use WPClamAV\Notifications\NotificationManager;
use WPClamAV\Resources\MailCapacityGuard;

$checks = 0;
function notification_check(bool $condition, string $message): void
{
    global $checks; ++$checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function notification_throws(callable $callback, string $message): void
{
    try { $callback(); } catch (Throwable) { notification_check(true, $message); return; }
    notification_check(false, $message);
}

$directories = [];
function notification_fixture(string $status = 'clean', bool $completed = true, bool $assets = true): array
{
    global $directories;
    $directory = sys_get_temp_dir() . '/wp-clamav-notification-' . bin2hex(random_bytes(12));
    mkdir($directory, 0700); $directories[] = $directory;
    $store = new NotificationMemoryStore($directory);
    $repository = new NotificationMemoryRepository();
    $event = $store->add($status, $completed);
    $rule = NotificationManager::defaultRules('reviewer@example.test')[0];
    $rule['events'] = ['clean', 'detected', 'held', 'failed'];
    $rule['attach_original'] = $assets; $rule['include_download_link'] = $assets;
    $state = (object) ['now' => 1700000000, 'messages' => [], 'allowMail' => true, 'allowAttachment' => true,
        'mailAccepted' => true, 'mailCalls' => 0, 'attachmentChecks' => 0, 'capacityCalls' => []];
    $settings = ['notification_rules' => [$rule], 'notification_rule_epoch' => 1,
        'download_url' => 'https://example.test/wp-admin/admin-post.php?action=wp_clamav_download', 'otp_pepper' => str_repeat('s', 48)];
    $mailer = static function (array $message) use ($state): bool {
        foreach ($message['attachments'] as $attachment) {
            notification_check(is_file($attachment['path']), 'private attachment exists while mailer runs');
            notification_check((fileperms($attachment['path']) & 0777) === 0600, 'attachment copy permissions are private');
            $message['attachment_sha256'][] = hash_file('sha256', $attachment['path']);
        }
        $state->messages[] = $message;
        return $state->mailAccepted;
    };
    $capacity = static function (string $kind, string $recipient, int $original, int $encoded) use ($state): bool {
        $state->capacityCalls[] = compact('kind', 'recipient', 'original', 'encoded');
        notification_check($encoded >= 0 && $original >= 0, 'resource policy receives nonnegative sizes');
        if ($kind === 'attachment') { ++$state->attachmentChecks; return $state->allowAttachment; }
        notification_check($kind === 'mail', 'only mail consumes recipient tokens');
        ++$state->mailCalls; return $state->allowMail;
    };
    $manager = new NotificationManager($store, $repository, $settings, $mailer, $capacity, $directory, static fn(): int => $state->now);
    return compact('directory', 'store', 'repository', 'event', 'rule', 'state', 'settings', 'mailer', 'capacity', 'manager');
}
function notification_link(array $message): array
{
    preg_match('~https://example\.test/[^\s]+~', $message['body'], $matches);
    notification_check(isset($matches[0]), 'notification contains a review link');
    parse_str((string) parse_url($matches[0], PHP_URL_QUERY), $query);
    return [$query['grant'], $query['token']];
}
function notification_code(array $message): string
{
    preg_match('/Verification code: ([0-9]{6})/', $message['body'], $matches);
    notification_check(isset($matches[1]), 'OTP email contains a six-digit code');
    return $matches[1];
}

try {
    $defaults = NotificationManager::defaultRules('admin@example.test');
    notification_check($defaults[0]['events'] === ['detected', 'held', 'failed'], 'default event selection');
    notification_check(!$defaults[0]['attach_original'] && !$defaults[0]['include_download_link'], 'defaults never share files');
    notification_check(NotificationManager::defaultRules("a@example.test\r\nBcc: evil@example.test") === [], 'invalid administrator recipient rejected');
    foreach ([['recipients' => ["a@example.test\nBcc:evil@example.test"]], ['subject' => "subject\nBcc:x"],
        ['body' => '{private_path}'], ['events' => ['unknown']], ['form_ids' => [-1]]] as $override) {
        notification_throws(static fn() => NotificationManager::validateRules([array_replace($defaults[0], $override)]), 'invalid rule rejected');
    }
    notification_check(NotificationManager::encodedAttachmentBytes(3) === 6, 'MIME CRLF overhead charged');
    notification_check(NotificationManager::encodedAttachmentBytes(57) === 78, '76-column MIME boundary');
    notification_throws(static fn() => NotificationManager::encodedAttachmentBytes(PHP_INT_MAX), 'MIME size overflow rejected');

    extract(notification_fixture('clean', true, false));
    $manager->onEvent($event); $manager->runQueue();
    $call = $state->capacityCalls[0]; $message = $state->messages[0];
    notification_check($call['kind'] === 'mail' && $call['original'] === 0,
        'data-only result capacity never counts the retained original as an attachment');
    notification_check($call['encoded'] >= MailCapacityGuard::estimatedMessageBytes($message['subject'], $message['body'], [], 4096),
        'complete plain result includes conservative transfer encoding and framing');

    extract(notification_fixture('clean', true, true));
    $manager->onEvent($event); $manager->runQueue();
    $call = $state->capacityCalls[1]; $message = $state->messages[0];
    notification_check($call['kind'] === 'mail' && $call['original'] === $event['size'],
        'attachment mail capacity counts the original actually attached');
    notification_check($call['encoded'] >= MailCapacityGuard::estimatedMessageBytes($message['subject'], $message['body'], [$event['size']], 4096),
        'complete attachment mail includes report text and one full MIME original');
    [$grantId, $secret] = notification_link($message);
    $manager->requestOtp($grantId, $secret, '192.0.2.8'); $manager->runQueue();
    $call = $state->capacityCalls[2]; $message = $state->messages[1];
    notification_check($call['kind'] === 'mail' && $call['original'] === 0 && $call['encoded'] >= MailCapacityGuard::estimatedMessageBytes($message['subject'], $message['body'], [], 4096),
        'OTP is a complete bounded message with no original allocation');

    // Every status/completion combination reaches the actual mail boundary with both asset flags requested.
    foreach (['clean', 'detected', 'held', 'failed'] as $status) {
        foreach ([true, false] as $completed) {
            extract(notification_fixture($status, $completed));
            $manager->onEvent($event); $manager->onEvent($event);
            notification_check(count($repository->deliveries) === 1, 'automatic enqueue is idempotent');
            $result = $manager->runQueue();
            notification_check($result['accepted'] === 1 && count($state->messages) === 1, 'one result mail accepted');
            $message = $state->messages[0];
            $eligible = $status !== 'failed' && $completed;
            notification_check((count($message['attachments']) === 1) === $eligible, 'status/completion attachment matrix');
            notification_check((count($repository->grants) === 1) === $eligible, 'status/completion capability matrix');
            notification_check(str_contains($message['body'], 'https://example.test/') === $eligible, 'status/completion link matrix');
            notification_check($state->mailCalls === 1, 'one recipient token admission per message');
            if ($eligible) {
                notification_check($message['attachment_sha256'][0] === $event['sha256'], 'attachment is exact original');
                notification_check(!file_exists($message['attachments'][0]['path']), 'temporary attachment removed after send');
            }
        }
    }

    extract(notification_fixture('clean', true, false));
    $rules = [$rule, array_replace($rule, ['id' => 'second-rule', 'recipients' => ['a@example.test', 'b@example.test'], 'subject' => 'Second {status}'])];
    $settings['notification_rules'] = $rules;
    $manager = new NotificationManager($store, $repository, $settings, $mailer, $capacity, $directory, static fn(): int => $state->now);
    $manager->onEvent($event); $manager->runQueue();
    notification_check(count($state->messages) === 3, 'multiple messages and recipients per event');
    notification_check(count(array_unique(array_column($state->messages, 'to'))) === 3, 'recipients receive separate messages');
    foreach ($state->messages as $message) { notification_check(count($message['headers']) === 1, 'recipient lists not exposed in CC/BCC headers'); }
    $manager->resend($event['id'], $rule['id'], 'administrator:1', 'Requested review resend'); $manager->runQueue();
    notification_check(count($state->messages) === 4, 'audited manual resend creates a new delivery');
    notification_throws(fn() => $manager->resend($event['id'], $rule['id'], '', ''), 'resend actor/reason required');

    // Native heuristic detections may include a completed protocol envelope
    // while explicitly reporting unscanned content. Asset checks must honor
    // those warnings, including a resource-limit detection.
    foreach (['detected', 'held'] as $status) {
        foreach (['scan-limit-encryption-or-parser-alert', 'parser-error', 'encrypted-content', 'unknown-or-unsupported-layer'] as $reason) {
            extract(notification_fixture($status, true, true));
            $event['reason_codes'] = [$reason]; $store->events[$event['id']] = $event;
            $manager->onEvent($event); $manager->runQueue();
            notification_check(count($state->messages) === 1, 'incomplete native findings still send a result report');
            notification_check($state->messages[0]['attachments'] === [] && !$state->messages[0]['asset_eligible'], 'incomplete native findings cannot attach originals');
            notification_check($repository->grants === [] && !str_contains($state->messages[0]['body'], 'https://example.test/'), 'incomplete native findings cannot create review links');
            notification_check($store->opens === 0 && $state->attachmentChecks === 0, 'limit reports never copy or open originals for notification');
        }
    }

    // OTP is queued, short-lived only from actual dispatch, one use, and does not buffer/download on GET.
    extract(notification_fixture());
    $manager->onEvent($event); $manager->runQueue();
    [$grantId, $secret] = notification_link($state->messages[0]);
    $serialized = json_encode($repository->grants);
    notification_check(!str_contains($serialized, $secret), 'invitation secret stored only as a hash');
    $opens = $store->opens; $sent = count($state->messages);
    notification_check($manager->invitation($grantId, $secret) !== null, 'landing page has valid invitation metadata');
    notification_check($store->opens === $opens && count($state->messages) === $sent, 'GET metadata does not open original or send OTP');
    notification_check($manager->invitation($grantId, str_repeat('0', 64)) === null, 'wrong invitation token rejected');
    notification_check($manager->requestOtp($grantId, $secret, '192.0.2.1'), 'OTP request queued');
    notification_check($manager->requestOtp($grantId, $secret, '192.0.2.1'), 'pending OTP request is idempotent');
    notification_check(count($repository->deliveries) === 2 && count($state->messages) === 1, 'request does not synchronously send mail');
    $state->now += 3600; $manager->runQueue();
    $otp = notification_code($state->messages[1]);
    notification_check($repository->grants[$grantId]['challenge_expires_at'] === $state->now + 600, 'OTP lifetime begins at dispatch');
    notification_check(!str_contains(json_encode($repository->grants), '"' . $otp . '"'), 'OTP code not stored plaintext');
    notification_check($manager->verifyOtp($grantId, $secret, 'not-a-code', '192.0.2.1') === null, 'malformed OTP rejected');
    $session = $manager->verifyOtp($grantId, $secret, $otp, '192.0.2.1');
    notification_check(is_string($session) && strlen($session) === 64, 'recipient obtains session after correct OTP');
    notification_check($manager->verifyOtp($grantId, $secret, $otp) === null, 'OTP cannot be replayed');
    notification_check(!str_contains(json_encode($repository->grants), $session), 'session secret stored only as hash');
    $opens = $store->opens;
    notification_check($manager->hasDownloadSession($grantId, $session), 'download session metadata valid');
    notification_check($store->opens === $opens, 'session GET check does not read bytes');
    $download = $manager->openDownload($grantId, $session);
    notification_check(stream_get_contents($download['stream']) === 'harmless original bytes', 'verified download returns original bytes');
    fclose($download['stream']);
    notification_throws(fn() => $manager->openDownload($grantId, str_repeat('a', 64)), 'unverified browser cannot download');
    $state->now += 901;
    notification_throws(fn() => $manager->openDownload($grantId, $session), 'session expiry enforced without deleting original');
    notification_check(is_file($store->path), 'credential expiry does not expire retained original');

    // Attempt limits and fresh-code requests share recipient/IP security throttles.
    extract(notification_fixture());
    $manager->onEvent($event); $manager->runQueue(); [$grantId, $secret] = notification_link($state->messages[0]);
    $manager->requestOtp($grantId, $secret, '192.0.2.2'); $manager->runQueue(); $otp = notification_code($state->messages[1]);
    $wrong = $otp === '000000' ? '111111' : '000000';
    for ($i = 0; $i < 5; ++$i) { notification_check($manager->verifyOtp($grantId, $secret, $wrong, '192.0.2.2') === null, 'wrong OTP attempt rejected'); }
    notification_check($manager->verifyOtp($grantId, $secret, $otp, '192.0.2.2') === null, 'correct OTP rejected after attempt ceiling');
    for ($i = 0; $i < 2; ++$i) { notification_check($manager->requestOtp($grantId, $secret, '192.0.2.2'), 'bounded resend accepted'); $manager->runQueue(); }
    notification_check(!$manager->requestOtp($grantId, $secret, '192.0.2.2'), 'OTP request ceiling enforced');

    // Later failure revokes existing grants and strips assets from old queued/resend messages.
    extract(notification_fixture());
    $manager->onEvent($event); $manager->runQueue(); [$grantId, $secret] = notification_link($state->messages[0]);
    $failed = $store->add('failed', false); $manager->onEvent($failed);
    notification_check($repository->grants[$grantId]['revoked_at'] > 0, 'new failed attempt revokes prior grant');
    notification_check($manager->invitation($grantId, $secret) === null, 'failed rescan disables old link');
    $manager->resend($event['id'], $rule['id'], 'admin', 'Review old event'); $manager->runQueue();
    foreach (array_slice($state->messages, 1) as $message) {
        notification_check($message['attachments'] === [] && !str_contains($message['body'], 'https://example.test/'), 'failure and stale event resend both text-only');
    }
    $newClean = $store->add('clean'); $manager->onEvent($newClean);
    $manager->resend($failed['id'], $rule['id'], 'admin', 'Review failed attempt after later success'); $manager->runQueue();
    $last = $state->messages[array_key_last($state->messages)];
    notification_check($last['event_id'] === $failed['id'] && $last['attachments'] === [] && !str_contains($last['body'], 'https://'), 'failed-event resend cannot inherit later clean assets');

    extract(notification_fixture());
    $store->receipts[$event['upload_id']]['complete'] = false;
    $manager->onEvent($event); $manager->runQueue();
    notification_check($state->messages[0]['attachments'] === [] && count($repository->grants) === 0,
        'even a clean label cannot share an incompletely preserved original');

    extract(notification_fixture('held', true, false));
    $rule['body'] = str_repeat('{findings}', 6000);
    $settings['notification_rules'] = [$rule];
    $store->events[$event['id']]['findings'] = array_fill(0, 100, str_repeat('f', 512));
    $manager = new NotificationManager($store, $repository, $settings, $mailer, $capacity, $directory, static fn(): int => $state->now);
    $manager->onEvent($event); $manager->runQueue();
    notification_check(strlen($state->messages[0]['body']) < 67000 && str_contains($state->messages[0]['body'], 'report shortened'),
        'repeated expanding placeholders cannot allocate an unbounded mail body');

    extract(notification_fixture());
    $manager->onEvent($event); $manager->runQueue(); [$grantId, $secret] = notification_link($state->messages[0]);
    ++$settings['notification_rule_epoch'];
    $manager2 = new NotificationManager($store, $repository, $settings, $mailer, $capacity, $directory, static fn(): int => $state->now);
    notification_check($manager2->invitation($grantId, $secret) === null, 'rule epoch invalidates grants after recipient/rule changes');

    extract(notification_fixture());
    $oldEvent = $event;
    $manager->onEvent($oldEvent); $manager->runQueue();
    $newEvent = $store->add('clean'); $manager->onEvent($newEvent); $manager->runQueue();
    [$newGrantId, $newSecret] = notification_link($state->messages[1]);
    $manager->onEvent($oldEvent);
    notification_check($manager->invitation($newGrantId, $newSecret) !== null, 'reconciling old events cannot revoke a newer grant');

    extract(notification_fixture());
    $manager->onEvent($event); file_put_contents($store->path, 'tampered bytes'); $manager->runQueue();
    notification_check($state->messages[0]['attachments'] === [] && !str_contains($state->messages[0]['body'], 'https://'), 'tampered original never attached or linked');
    notification_check(count($repository->grants) === 0, 'tampered original creates no capability');

    extract(notification_fixture());
    $state->allowAttachment = false; $manager->onEvent($event); $manager->runQueue();
    notification_check($state->messages[0]['attachments'] === [] && str_contains($state->messages[0]['body'], 'https://'), 'calculated attachment limit can fall back to explicitly enabled OTP link');
    notification_check($state->mailCalls === 1, 'attachment denial does not consume extra mail tokens');

    extract(notification_fixture());
    $racingCapacity = static function (string $kind, string $recipient, int $original, int $encoded) use ($store): bool {
        if ($kind === 'mail') { $store->add('failed', false); }
        return true;
    };
    $manager = new NotificationManager($store, $repository, $settings, $mailer, $racingCapacity, $directory, static fn(): int => $state->now);
    $manager->onEvent($event); $manager->runQueue();
    notification_check($state->messages[0]['attachments'] === [] && !str_contains($state->messages[0]['body'], 'https://'),
        'failure while waiting for mail capacity strips assets at the final boundary');

    extract(notification_fixture());
    $expiringCapacity = static function (string $kind, string $recipient, int $original, int $encoded) use ($state): bool {
        if ($kind === 'mail') { $state->now += 121; }
        return true;
    };
    $manager = new NotificationManager($store, $repository, $settings, $mailer, $expiringCapacity, $directory, static fn(): int => $state->now);
    $manager->onEvent($event); $manager->runQueue();
    notification_check($state->messages === [], 'an expired worker cannot mail after waiting for capacity');
    foreach ($repository->grants as $grant) { notification_check($grant['revoked_at'] > 0, 'expired worker does not leave an unsent live grant'); }
    notification_check(glob($directory . '/.mail-*') === [], 'expired worker cleans its temporary original');

    extract(notification_fixture());
    $manager->onEvent($event); $manager->runQueue(); [$grantId, $secret] = notification_link($state->messages[0]);
    $manager->requestOtp($grantId, $secret, '192.0.2.9');
    $manager = new NotificationManager($store, $repository, $settings, $mailer, $expiringCapacity = static function (string $kind) use ($state): bool {
        if ($kind === 'mail') { $state->now += 121; }
        return true;
    }, $directory, static fn(): int => $state->now);
    $manager->runQueue();
    notification_check(count($state->messages) === 1, 'expired OTP worker cannot send a code');

    extract(notification_fixture());
    $replacementCapacity = static function (string $kind) use ($state, $store, $repository, $settings, $mailer, $capacity, $directory): bool {
        if ($kind === 'mail') {
            $state->now += 121;
            $replacement = new NotificationManager($store, $repository, $settings, $mailer, $capacity, $directory, static fn(): int => $state->now);
            $replacement->runQueue();
        }
        return true;
    };
    $manager = new NotificationManager($store, $repository, $settings, $mailer, $replacementCapacity, $directory, static fn(): int => $state->now);
    $manager->onEvent($event); $manager->runQueue();
    notification_check(count($state->messages) === 1, 'only replacement lease holder sends the message');
    [$grantId, $secret] = notification_link($state->messages[0]);
    notification_check($manager->invitation($grantId, $secret) !== null, 'expired worker cannot revoke the replacement workers separate grant');

    extract(notification_fixture());
    $state->allowMail = false; $manager->onEvent($event); $manager->runQueue();
    $row = array_values($repository->deliveries)[0];
    notification_check($state->messages === [] && $row['state'] === 'retry' && $row['attempts'] === 0, 'resource deferral consumes no transport attempt');
    foreach ($repository->grants as $grant) { notification_check($grant['revoked_at'] > 0, 'unsent invitation revoked'); }
    notification_check(glob($directory . '/.mail-*') === [], 'deferred delivery cleans temporary attachments');

    extract(notification_fixture('failed', false));
    $state->mailAccepted = false; $manager->onEvent($event);
    for ($i = 0; $i < 4; ++$i) { $manager->runQueue(); $state->now += 100000; }
    $row = array_values($repository->deliveries)[0];
    notification_check($row['state'] === 'failed' && $row['attempts'] === 4, 'transport retries stop at configured attempt limit');
    notification_check(count($state->messages) === 4, 'one transport invocation per delivery attempt');
    foreach ($state->messages as $message) { notification_check($message['attachments'] === [] && !str_contains($message['body'], 'https://'), 'all failed-scan retry paths remain text-only'); }

    extract(notification_fixture('failed', false));
    $manager = new NotificationManager($store, $repository, $settings, static function (): bool { throw new RuntimeException('mail transport exception'); },
        $capacity, $directory, static fn(): int => $state->now);
    $manager->onEvent($event);
    for ($i = 0; $i < 4; ++$i) { $manager->runQueue(); $state->now += 100000; }
    $row = array_values($repository->deliveries)[0];
    notification_check($row['state'] === 'failed' && $row['attempts'] === 4, 'transport exceptions also count toward bounded retries');

    extract(notification_fixture('clean', true, false));
    $manager->onEvent($event);
    $firstLease = $repository->claim($state->now, 30, 1)[0];
    notification_check($repository->claim($state->now, 30, 1) === [], 'live lease prevents duplicate workers');
    $secondLease = $repository->claim($state->now + 31, 30, 1)[0];
    $repository->finish($firstLease['id'], $firstLease['lease_token'], 'accepted', $state->now + 32, 0, '', true);
    notification_check($repository->delivery($firstLease['id'])['state'] === 'sending', 'stale worker cannot finish a replacement lease');
    $repository->finish($secondLease['id'], $secondLease['lease_token'], 'accepted', $state->now + 32, 0, '', true);
    notification_check($repository->delivery($firstLease['id'])['state'] === 'accepted', 'current lease owner can finish');

    echo json_encode(['suite' => 'notifications', 'checks' => $checks, 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    foreach ($directories as $directory) {
        foreach (glob($directory . '/*') ?: [] as $path) { @unlink($path); }
        foreach (glob($directory . '/.mail-*') ?: [] as $path) { @unlink($path); }
        @rmdir($directory);
    }
}
