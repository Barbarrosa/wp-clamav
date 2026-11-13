<?php
declare(strict_types=1);

namespace WPClamAV\Notifications;

use Throwable;

/** Public landing/OTP routes never expose a filesystem path or anonymous file URL. */
final class WordPressNotificationController
{
    public function __construct(private NotificationManager $manager) {}

    public function registerHooks(): void
    {
        add_action('wp_clamav_scan_event', [$this, 'onEvent']);
        add_action('wp_clamav_mail_queue', [$this, 'runQueue']);
        add_action('admin_post_wp_clamav_download', [$this, 'downloadAction']);
        add_action('admin_post_nopriv_wp_clamav_download', [$this, 'downloadAction']);
    }

    public function onEvent(array $event): void
    {
        $this->manager->onEvent($event);
        $this->scheduleQueue();
    }

    public function scheduleQueue(): void
    {
        if (!wp_next_scheduled('wp_clamav_mail_queue')) {
            wp_schedule_single_event(time() + 1, 'wp_clamav_mail_queue');
        }
    }

    public function runQueue(): array
    {
        // Capacity is checked for every recipient by the injected site resource policy.
        return $this->manager->runQueue();
    }

    private function value(array $source, string $key): string
    {
        return isset($source[$key]) && is_string($source[$key]) ? wp_unslash($source[$key]) : '';
    }

    private function securityHeaders(): void
    {
        if (headers_sent()) { $this->error(); }
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, max-age=0');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    }

    private function error(): never
    {
        wp_die(esc_html__('This review link is invalid, unavailable, or expired. Request a new notification from the site administrator.', 'wp-clamav'),
            esc_html__('Review unavailable', 'wp-clamav'), ['response' => 403]);
        exit;
    }

    private function cookieName(string $id): string { return 'wp_clamav_review_' . $id; }

    private function csrfAction(string $id, string $token): string
    {
        return 'wp-clamav-review-' . $id . '-' . hash('sha256', $token);
    }

    public function downloadAction(): never
    {
        $this->securityHeaders();
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $source = $method === 'POST' ? $_POST : $_GET;
        $id = $this->value($source, 'grant');
        $token = $this->value($source, 'token');
        try {
            $invitation = $this->manager->invitation($id, $token);
        } catch (Throwable) { $this->error(); }
        if ($invitation === null || !in_array($method, ['GET', 'POST'], true)) { $this->error(); }
        $message = '';
        $session = $this->value($_COOKIE, $this->cookieName($id));
        if ($method === 'POST') {
            $nonce = $this->value($_POST, '_wpnonce');
            if (!wp_verify_nonce($nonce, $this->csrfAction($id, $token))) { $this->error(); }
            $stage = $this->value($_POST, 'stage');
            $requester = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            try {
                if ($stage === 'request_otp') {
                    if (!$this->manager->requestOtp($id, $token, $requester)) { $this->error(); }
                    $this->scheduleQueue();
                    $message = __('A verification email has been queued for the recipient. Delivery depends on the site mail service and its available capacity.', 'wp-clamav');
                } elseif ($stage === 'verify_otp') {
                    $session = $this->manager->verifyOtp($id, $token, $this->value($_POST, 'code'), $requester) ?? '';
                    if ($session === '') { $this->error(); }
                    $cookiePath = parse_url(admin_url('admin-post.php'), PHP_URL_PATH) ?: '/';
                    if (!setcookie($this->cookieName($id), $session, [
                        'expires' => 0, 'path' => $cookiePath, 'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
                    ])) { $this->error(); }
                    $message = __('Recipient verified. This is access for review, not approval to publish or execute the file.', 'wp-clamav');
                } elseif ($stage === 'download') {
                    $download = $this->manager->openDownload($id, $session);
                    header('Content-Type: application/octet-stream');
                    header('Content-Length: ' . $download['size']);
                    header("Content-Disposition: attachment; filename=\"review-file\"; filename*=UTF-8''" . rawurlencode($download['name']));
                    try {
                        while (!feof($download['stream']) && !connection_aborted()) {
                            $chunk = fread($download['stream'], 65536);
                            if ($chunk === false) { break; }
                            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- authenticated binary download.
                        }
                    } finally { fclose($download['stream']); }
                    exit;
                } else { $this->error(); }
            } catch (Throwable) { $this->error(); }
        }
        $verified = $session !== '' && $this->manager->hasDownloadSession($id, $session);
        header('Content-Type: text/html; charset=UTF-8');
        $action = esc_url(admin_url('admin-post.php'));
        $hidden = '<input type="hidden" name="action" value="wp_clamav_download">'
            . '<input type="hidden" name="grant" value="' . esc_attr($id) . '">'
            . '<input type="hidden" name="token" value="' . esc_attr($token) . '">'
            . wp_nonce_field($this->csrfAction($id, $token), '_wpnonce', false, false);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__('Protected upload review', 'wp-clamav')
            . '</title></head><body><main><h1>' . esc_html__('Protected upload review', 'wp-clamav') . '</h1>';
        echo '<p>' . esc_html__('The original may be retained by this website indefinitely. Link expiry does not delete the stored file.', 'wp-clamav') . '</p>';
        echo '<p>' . esc_html(sprintf(__('Scan disposition: %s', 'wp-clamav'), $invitation['status'])) . '</p>';
        if ($message !== '') { echo '<p role="status">' . esc_html($message) . '</p>'; }
        if ($verified) {
            echo '<form method="post" action="' . $action . '">' . $hidden // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled escaped fields and WP nonce.
                . '<input type="hidden" name="stage" value="download"><button type="submit">'
                . esc_html__('Download original for review', 'wp-clamav') . '</button></form>';
        } else {
            echo '<p>' . esc_html(sprintf(__('Verification is sent only to %s.', 'wp-clamav'), $invitation['recipient'])) . '</p>';
            echo '<form method="post" action="' . $action . '">' . $hidden // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                . '<input type="hidden" name="stage" value="request_otp"><button type="submit">'
                . esc_html__('Email verification code', 'wp-clamav') . '</button></form>';
            echo '<form method="post" action="' . $action . '">' . $hidden // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                . '<input type="hidden" name="stage" value="verify_otp"><label>' . esc_html__('Verification code', 'wp-clamav')
                . ' <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label>'
                . '<button type="submit">' . esc_html__('Verify recipient', 'wp-clamav') . '</button></form>';
        }
        echo '</main></body></html>';
        exit;
    }
}
