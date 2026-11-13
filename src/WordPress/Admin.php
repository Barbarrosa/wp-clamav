<?php
declare(strict_types=1);

namespace WPClamAV\WordPress;

use WPClamAV\Application;
use WPClamAV\Notifications\AssetPolicy;
use WPClamAV\Storage\Files;

final class Admin
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('network_admin_menu', [$this, 'networkMenu']);
        add_action('admin_notices', [$this, 'notice']);
        add_action('network_admin_notices', [$this, 'notice']);
        add_action('admin_post_wp_clamav_save_settings', [$this, 'saveSettings']);
        add_action('admin_post_wp_clamav_download_original', [$this, 'downloadOriginal']);
        foreach (['retry', 'full_scan', 'manual_approve', 'delete', 'resend', 'run_updates'] as $action) {
            add_action('admin_post_wp_clamav_' . $action, function () use ($action): void {
                $this->action($action);
            });
        }
    }

    public function menu(): void
    {
        if (!Settings::networkManaged()) {
            add_management_page('WP ClamAV', 'WP ClamAV', Settings::capability(), 'wp-clamav', [$this, 'render']);
        }
    }

    public function networkMenu(): void
    {
        if (Settings::networkManaged()) {
            add_submenu_page('settings.php', 'WP ClamAV', 'WP ClamAV', Settings::capability(), 'wp-clamav', [$this, 'render']);
        }
    }

    public static function url(string $tab = 'review', array $args = []): string
    {
        $base = Settings::networkManaged() ? network_admin_url('settings.php') : admin_url('tools.php');
        return add_query_arg(array_merge(['page' => 'wp-clamav', 'tab' => $tab], $args), $base);
    }

    public function notice(): void
    {
        if (!current_user_can(Settings::capability())) {
            return;
        }
        if (!Settings::enabled()) {
            echo '<div class="notice notice-warning"><p>WP ClamAV upload protection is disabled. ';
            echo '<a href="' . esc_url(self::url('settings')) . '">Configure and enable protection</a>.</p></div>';
        } elseif (Plugin::bootError() !== null) {
            echo '<div class="notice notice-error"><p>WP ClamAV could not initialize. Protected uploads cannot be published. ';
            echo esc_html(Plugin::bootError()) . ' <a href="' . esc_url(self::url('diagnostics')) . '">Review configuration</a>.</p></div>';
        }
    }

    public function render(): void
    {
        $this->requireCapability();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'review';
        echo '<div class="wrap"><h1>WP ClamAV</h1><nav class="nav-tab-wrapper">';
        foreach (['review' => 'Uploads and review', 'settings' => 'Settings', 'notifications' => 'Notifications', 'diagnostics' => 'Capacity and diagnostics'] as $key => $label) {
            echo '<a class="nav-tab' . ($tab === $key ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url($key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if (isset($_GET['message'])) {
            echo '<div class="notice notice-info"><p>' . esc_html(wp_unslash((string) $_GET['message'])) . '</p></div>';
        }
        try {
            match ($tab) {
                'settings' => $this->settings(),
                'notifications' => $this->notifications(),
                'diagnostics' => $this->diagnostics(),
                default => $this->review(),
            };
        } catch (\Throwable $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error->getMessage()) . '</p></div>';
        }
        echo '</div>';
    }

    private function settings(): void
    {
        $s = Settings::get();
        echo '<p>Original files, submitted information and scan history remain in private storage indefinitely. Disabling or uninstalling this plugin does not delete them.</p>';
        $this->startSettingsForm('settings');
        echo '<table class="form-table"><tbody>';
        $this->checkbox('enabled', 'Enable protection', (bool) $s['enabled'], 'New protected uploads are retained privately. Publication requires a qualified decision.');
        echo '<tr><th scope="row"><label for="backend">Scanning backend</label></th><td><select name="settings[backend]" id="backend">';
        foreach (['local' => 'Local native scanner', 'remote' => 'Authenticated HTTPS service'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($s['backend'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">Local execution must pass the account capacity and executable checks. HTTPS uses an explicitly configured external service.</p></td></tr>';
        $this->input('storage_path', 'Private storage directory', (string) $s['storage_path'], 'Absolute path outside every website document root. This is the permanent datastore, not Forminator temporary storage.');
        $this->input('local_binary', 'Native scanner executable', (string) $s['local_binary'], 'Absolute path to the qualified scanner executable.');
        $this->input('generation_dir', 'Verified database generation', (string) $s['generation_dir'], 'Absolute path to the prepared, verified signature generation.');
        $this->input('database_dir', 'Signature database directory', (string) $s['database_dir'], 'Absolute path to original signed database files.');
        $this->input('certificates_dir', 'Database certificates', (string) $s['certificates_dir'], 'Absolute path to the scanner certificate directory.');
        $this->input('state_dir', 'Update state directory', (string) $s['state_dir'], 'Private persistent directory for verified update state.');
        $this->input('updater_binary', 'Native updater executable', (string) $s['updater_binary'], 'Absolute path to the verified native updater.');
        $this->input('native_manifest', 'Native release manifest', (string) $s['native_manifest'], 'Absolute path to the installed native compatibility manifest.');
        $this->input('programs_dir', 'Private native program directory', (string) $s['programs_dir'], 'Existing owner-only directory outside every document root. Leave empty to use private-storage/programs.');
        echo '<tr><th scope="row"><label for="release_channel">Signed release channel</label></th><td><select id="release_channel" name="settings[release_channel]">';
        foreach (['stable' => 'Stable', 'preview' => 'Preview (explicit opt-in)'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($s['release_channel'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">Each channel requires its publisher verification key. Unsigned, expired and replayed releases are rejected.</p></td></tr>';
        $this->input('native_release_public_key', 'Native release verification key', (string) $s['native_release_public_key'], 'Standard base64 Ed25519 public key (32 bytes). WP_CLAMAV_NATIVE_RELEASE_PUBLIC_KEY overrides this public setting. Empty disables native code downloads; signed database updates remain independent.');
        $this->input('plugin_repository', 'GitHub plugin repository', (string) $s['plugin_repository'], 'Owner/repository of the separately published WordPress plugin. Empty leaves plugin release updates unconfigured.');
        $this->input('plugin_release_public_key', 'Plugin release verification key', (string) $s['plugin_release_public_key'], 'Standard base64 Ed25519 public key (32 bytes). WP_CLAMAV_PLUGIN_RELEASE_PUBLIC_KEY overrides this public setting. ZIP contents are authenticated before installation.');
        $this->checkbox('plugin_auto_updates', 'Install signed plugin updates automatically', (bool) $s['plugin_auto_updates'], 'Only after repository, trust key and staging/work allocations are configured. WordPress filesystem permissions and automatic-updater checks still apply.');
        $this->input('endpoint', 'Remote HTTPS endpoint', (string) $s['endpoint'], 'No upload data is sent remotely unless this backend is selected.');
        echo '<tr><th scope="row"><label for="remote_token">Service credential</label></th><td><input type="password" autocomplete="new-password" class="regular-text" id="remote_token" name="settings[remote_token]" value="" placeholder="' . esc_attr($s['remote_token'] !== '' ? 'Configured; leave blank to keep' : 'Not configured') . '">';
        echo '<p><label><input type="checkbox" name="settings[clear_remote_token]" value="1"> Clear stored credential</label></p><p class="description">WP_CLAMAV_REMOTE_TOKEN in wp-config.php takes precedence over the stored credential.</p></td></tr>';
        echo '<tr><th scope="row"><label for="profile">Default scan profile</label></th><td><select name="settings[profile]" id="profile">';
        foreach (['pdf-jpeg-png-pe-exclusions' => 'PDF/JPEG/PNG profile with PE exclusions', 'full' => 'Full database'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($s['profile'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">A reduced scan without proven applicability and complete parsing remains held. Unknown file types and incomplete scans are not cleared.</p></td></tr>';
        $this->input('forminator_forms', 'Protected Forminator form IDs', implode(', ', $s['forminator_forms']), 'Empty protects every Forminator form. To restrict protection, list comma-separated form IDs. This adapter is qualified for Forminator 1.58.4. Narrative submission remains independent of scan completion.');
        echo '<tr><th scope="row"><label for="budgets">Account constraints and operating budgets</label></th><td><textarea class="large-text code" rows="9" id="budgets" name="settings[budgets]">' . esc_textarea($this->json($s['budgets'] === [] ? new \stdClass() : $s['budgets'])) . '</textarea><p class="description">JSON object of confirmed numeric account limits and operating reserves. PHP and WordPress limits are detected automatically. Private intake requires a storage allocation and reserves; scanning requires bounded work allowances. Unknown provider quotas remain unknown. Review the diagnostics tab before enabling intake.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Save configuration');
        echo '</form>';
    }

    private function notifications(): void
    {
        $s = Settings::get();
        $rules = $s['notification_rules'];
        $rules[] = ['id' => '', 'enabled' => false, 'events' => [], 'form_ids' => [], 'recipients' => [], 'subject' => '', 'body' => '', 'attach_original' => false, 'include_download_link' => false];
        echo '<p>Each enabled rule sends a separate message to each listed recipient. Failed and incomplete scans never include an original attachment or original-file download link. Narrative notifications remain independent.</p>';
        $this->startSettingsForm('notifications');
        foreach ($rules as $index => $rule) {
            $prefix = 'rules[' . $index . ']';
            echo '<fieldset style="border:1px solid #c3c4c7;padding:16px;margin-top:16px"><legend><strong>' . esc_html($rule['id'] !== '' ? (string) $rule['id'] : 'Add a notification rule') . '</strong></legend>';
            echo '<p><label>Rule ID <input name="' . esc_attr($prefix . '[id]') . '" value="' . esc_attr((string) $rule['id']) . '" pattern="[A-Za-z0-9_-]+"></label> <label><input type="checkbox" name="' . esc_attr($prefix . '[enabled]') . '" value="1"' . checked(!empty($rule['enabled']), true, false) . '> Enabled</label></p>';
            echo '<p>Scan events: ';
            foreach (['clean', 'detected', 'held', 'failed'] as $status) {
                echo '<label style="margin-right:12px"><input type="checkbox" name="' . esc_attr($prefix . '[events][]') . '" value="' . esc_attr($status) . '"' . checked(in_array($status, $rule['events'] ?? [], true), true, false) . '> ' . esc_html(ucfirst($status)) . '</label>';
            }
            echo '</p><p><label>Form IDs (empty = all) <input name="' . esc_attr($prefix . '[form_ids]') . '" value="' . esc_attr(implode(', ', $rule['form_ids'] ?? [])) . '"></label></p>';
            echo '<p><label>Recipients, separated by commas<br><input class="large-text" name="' . esc_attr($prefix . '[recipients]') . '" value="' . esc_attr(implode(', ', $rule['recipients'] ?? [])) . '"></label></p>';
            echo '<p><label>Subject<br><input class="large-text" name="' . esc_attr($prefix . '[subject]') . '" value="' . esc_attr((string) ($rule['subject'] ?? '')) . '"></label></p>';
            echo '<p><label>Plain-text message<br><textarea class="large-text" rows="5" name="' . esc_attr($prefix . '[body]') . '">' . esc_textarea((string) ($rule['body'] ?? '')) . '</textarea></label></p>';
            echo '<p><label><input type="checkbox" name="' . esc_attr($prefix . '[attach_original]') . '" value="1"' . checked(!empty($rule['attach_original']), true, false) . '> Include the original when policy and resource limits permit</label><br><label><input type="checkbox" name="' . esc_attr($prefix . '[include_download_link]') . '" value="1"' . checked(!empty($rule['include_download_link']), true, false) . '> Include a recipient-verified private download when permitted</label></p>';
            echo '<p class="description">These settings may include a known unsafe original after a completed detection if the delivery policy permits it. Choose recipients who are prepared to handle such evidence. They cannot override failed or incomplete scan restrictions.</p></fieldset>';
        }
        echo '<p class="description">Fields: {status}, {name}, {size}, {sha256}, {findings}, {reasons}, {engine_version}, {generation_id}, {profile}, {coverage}, {timestamp}, {receipt_id}, {event_id}, {download_link}. Private paths are never exposed.</p>';
        submit_button('Save notification rules');
        echo '</form>';
    }

    private function startSettingsForm(string $section): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wp_clamav_save_settings"><input type="hidden" name="section" value="' . esc_attr($section) . '">';
        wp_nonce_field('wp_clamav_save_settings');
    }

    private function input(string $key, string $label, string $value, string $description): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="' . esc_attr($key) . '" name="settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '"><p class="description">' . esc_html($description) . '</p></td></tr>';
    }

    private function checkbox(string $key, string $label, bool $checked, string $description): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="settings[' . esc_attr($key) . ']" value="1"' . checked($checked, true, false) . '> ' . esc_html($description) . '</label></td></tr>';
    }

    private function review(): void
    {
        echo '<p>Held, failed and detected originals remain private. Scan retries and administrative decisions are recorded without replacing earlier evidence.</p><form method="get"><input type="hidden" name="page" value="wp-clamav"><input type="hidden" name="tab" value="review"><label>Receipt ID <input name="receipt" value="' . esc_attr(isset($_GET['receipt']) ? wp_unslash((string) $_GET['receipt']) : '') . '"></label> <button class="button">Look up</button></form>';
        $id = isset($_GET['receipt']) ? trim(wp_unslash((string) $_GET['receipt'])) : '';
        if ($id !== '') {
            $this->receipt(Plugin::intake()->get($id));
            return;
        }
        $offset = max(0, isset($_GET['offset']) ? (int) $_GET['offset'] : 0);
        $rows = Plugin::storage()->list(50, $offset);
        $rows = isset($rows['items']) && is_array($rows['items']) ? $rows['items'] : $rows;
        echo '<table class="widefat striped" style="margin-top:16px"><thead><tr><th>Receipt</th><th>Original name</th><th>Status</th><th>Bytes</th><th>Received</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            echo '<tr><td><a href="' . esc_url(self::url('review', ['receipt' => $row['id']])) . '">' . esc_html((string) $row['id']) . '</a></td><td>' . esc_html((string) ($row['name'] ?? '')) . '</td><td>' . esc_html((string) ($row['status'] ?? 'held')) . '</td><td>' . esc_html((string) ($row['size'] ?? 0)) . '</td><td>' . esc_html($this->date($row['created_at'] ?? 0)) . '</td></tr>';
        }
        echo '</tbody></table><p>';
        if ($offset > 0) {
            echo '<a class="button" href="' . esc_url(self::url('review', ['offset' => max(0, $offset - 50)])) . '">Previous</a> ';
        }
        if (count($rows) === 50) {
            echo '<a class="button" href="' . esc_url(self::url('review', ['offset' => $offset + 50])) . '">Next</a>';
        }
        echo '</p>';
    }

    private function receipt(array $receipt): void
    {
        $id = (string) $receipt['id'];
        echo '<h2>' . esc_html((string) ($receipt['name'] ?? $id)) . '</h2><dl>';
        foreach (['id' => 'Receipt', 'status' => 'Current scan state', 'size' => 'Original bytes', 'sha256' => 'SHA-256', 'latest_event_id' => 'Latest scan event'] as $key => $label) {
            echo '<dt><strong>' . esc_html($label) . '</strong></dt><dd><code>' . esc_html((string) ($receipt[$key] ?? '')) . '</code></dd>';
        }
        echo '</dl><h3>Recorded receipt</h3><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html($this->json($this->redact($receipt))) . '</pre>';
        $this->actionForm('retry', $id, 'Queue another scan', false);
        $this->actionForm('full_scan', $id, 'Queue a full-database scan', false, 'Full scans still require the configured backend to satisfy all resource limits.');
        $this->actionForm('manual_approve', $id, 'Record manual authorization', true, 'This is an administrator decision, not a clean scanner verdict. Enter the reason and confirm that this original may be accessed.');
        $event = Plugin::storage()->latestEvent($id);
        if ($event !== null && AssetPolicy::eligible($event, $receipt, $event)) {
            echo '<h3>Download original for administrative review</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wp_clamav_download_original"><input type="hidden" name="receipt" value="' . esc_attr($id) . '"><input type="hidden" name="event_id" value="' . esc_attr($event['id']) . '"><input type="hidden" name="sha256" value="' . esc_attr($receipt['sha256']) . '">';
            wp_nonce_field('wp_clamav_download_original_' . $id . '_' . $event['id'] . '_' . $receipt['sha256']);
            echo '<p>The original may be unsafe. Downloading it does not authorize publication or execution. Failed and incomplete scans never expose original bytes.</p><label>Review reason <input name="reason" required maxlength="1000"></label><p><label><input type="checkbox" name="confirmed" value="1" required> I explicitly authorize this audited review download.</label></p><button class="button">Download original for review</button></form>';
        } else {
            echo '<p>Original download is unavailable because there is no current completed eligible scan. Failed and incomplete results never expose file bytes.</p>';
        }
        if (!empty($receipt['latest_event_id']) && Plugin::app()->notifications !== null) {
            echo '<h3>Resend a scan-event notification</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wp_clamav_resend"><input type="hidden" name="receipt" value="' . esc_attr($id) . '"><input type="hidden" name="event_id" value="' . esc_attr((string) $receipt['latest_event_id']) . '">';
            wp_nonce_field('wp_clamav_resend_' . $id);
            echo '<label>Rule ID <input name="rule_id" required></label> <label>Reason <input name="reason" required maxlength="1000"></label> <button class="button">Queue resend</button><p class="description">Resends apply the same attachment and download restrictions as automatic delivery.</p></form>';
        }
        $this->actionForm('delete', $id, 'Delete this original explicitly', true, 'This is permanent. Scheduled jobs, uninstall and ordinary Forminator deletion never perform this action.');
    }

    private function actionForm(string $action, string $id, string $label, bool $reason, string $description = ''): void
    {
        echo '<h3>' . esc_html($label) . '</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wp_clamav_' . esc_attr($action) . '"><input type="hidden" name="receipt" value="' . esc_attr($id) . '">';
        wp_nonce_field('wp_clamav_' . $action . '_' . $id);
        if ($description !== '') {
            echo '<p>' . esc_html($description) . '</p>';
        }
        if ($reason) {
            echo '<p><label>Reason <input class="regular-text" name="reason" required maxlength="1000"></label></p><p><label><input type="checkbox" name="confirmed" value="1" required> I confirm this explicit administrative action.</label></p>';
        }
        echo '<button class="button">' . esc_html($label) . '</button></form>';
    }

    private function diagnostics(): void
    {
        $report = Plugin::app()->doctor();
        $settings = Settings::get();
        $report['plugin_updates'] = ['repository' => $settings['plugin_repository'], 'channel' => $settings['release_channel'],
            'trust_key_configured' => $settings['plugin_release_public_key'] !== '',
            'automatic_updates' => $settings['plugin_auto_updates'], 'last_check' => get_site_option('wp_clamav_plugin_update_health', null)];
        echo '<p>Effective limits combine PHP, WordPress, Forminator, account quotas, scanner capacity and mail budgets. A provider limit that cannot be observed is reported as unknown.</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html($this->json($this->redact($report))) . '</pre>';
        echo '<p>Configure Site Tools cron to run due WordPress events at least every 30 minutes. Traffic-driven WP-Cron alone cannot guarantee a delivery or scan time.</p>';
        echo '<p>Large retained event histories can slow notification recovery: pages are limited, but locating a later page still walks earlier private event metadata. Monitor scheduled worker completion. Existing submission records and scan history are not automatically deleted.</p>';
        $this->actionForm('run_updates', '', 'Queue verified database and plugin update checks', false);
    }

    public function saveSettings(): void
    {
        $this->requireCapability();
        check_admin_referer('wp_clamav_save_settings');
        $section = isset($_POST['section']) ? sanitize_key(wp_unslash((string) $_POST['section'])) : 'settings';
        try {
            $input = Settings::get();
            if ($section === 'notifications') {
                $input['notification_rules'] = $this->submittedRules(isset($_POST['rules']) && is_array($_POST['rules']) ? wp_unslash($_POST['rules']) : []);
            } else {
                $posted = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
                $input = array_replace($input, $posted);
                $input['enabled'] = !empty($posted['enabled']);
                $input['plugin_auto_updates'] = !empty($posted['plugin_auto_updates']);
            }
            $settings = Settings::sanitize($input);
            if ($settings['enabled']) {
                Application::fromSettings($settings);
            }
            Settings::save($settings);
            Plugin::reset();
            $this->redirect($section, 'Configuration saved. Existing originals and history were retained.');
        } catch (\Throwable $error) {
            $this->redirect($section, 'Settings were not changed: ' . $error->getMessage());
        }
    }

    private function submittedRules(array $rows): array
    {
        $out = [];
        foreach (array_slice($rows, 0, 50) as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Invalid notification rule.');
            }
            if (trim((string) ($row['id'] ?? '')) === '' && trim((string) ($row['recipients'] ?? '')) === '') {
                continue;
            }
            $row['enabled'] = !empty($row['enabled']);
            $row['attach_original'] = !empty($row['attach_original']);
            $row['include_download_link'] = !empty($row['include_download_link']);
            $row['events'] = array_values((array) ($row['events'] ?? []));
            $row['recipients'] = preg_split('/[\s,]+/', trim((string) ($row['recipients'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $row['form_ids'] = array_values(array_map('intval', preg_split('/[\s,]+/', trim((string) ($row['form_ids'] ?? '')), -1, PREG_SPLIT_NO_EMPTY)));
            $out[] = $row;
        }
        return $out;
    }

    public function action(string $action): void
    {
        $this->requireCapability();
        $id = isset($_POST['receipt']) ? trim(wp_unslash((string) $_POST['receipt'])) : '';
        check_admin_referer('wp_clamav_' . $action . '_' . $id);
        $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash((string) $_POST['reason'])) : '';
        try {
            if (in_array($action, ['manual_approve', 'delete'], true) && ($reason === '' || empty($_POST['confirmed']))) {
                throw new \InvalidArgumentException('Enter a reason and explicitly confirm the action.');
            }
            switch ($action) {
                case 'retry':
                case 'full_scan':
                    Plugin::intake()->get($id);
                    Plugin::scheduleScan($id, $action === 'full_scan' ? 'full' : null);
                    $message = 'Scan queued. The original remains private until an authorized decision.';
                    break;
                case 'manual_approve':
                    Plugin::intake()->authorizeManual($id, get_current_user_id(), $reason);
                    $message = 'Manual authorization recorded separately from scanner evidence.';
                    break;
                case 'delete':
                    Plugin::intake()->delete($id, get_current_user_id(), $reason);
                    $id = '';
                    $message = 'Explicit deletion recorded.';
                    break;
                case 'resend':
                    if ($reason === '' || Plugin::app()->notifications === null) {
                        throw new \InvalidArgumentException('A reason and configured notification service are required.');
                    }
                    $event = isset($_POST['event_id']) ? trim(wp_unslash((string) $_POST['event_id'])) : '';
                    $rule = isset($_POST['rule_id']) ? trim(wp_unslash((string) $_POST['rule_id'])) : '';
                    Plugin::app()->notifications->resend($event, $rule, 'administrator:' . get_current_user_id(), $reason);
                    $message = 'Notification resend queued with the original event restrictions.';
                    break;
                case 'run_updates':
                    wp_schedule_single_event(time() + 1, 'wp_clamav_run_updates');
                    $message = 'Verified update checks queued.';
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown action.');
            }
            $this->redirect('review', $message, $id);
        } catch (\Throwable $error) {
            $this->redirect('review', 'Action was not completed: ' . $error->getMessage(), $id);
        }
    }

    public function downloadOriginal(): never
    {
        $this->requireCapability();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Use the authenticated review form to request an original.', 'wp-clamav'), '', ['response' => 405]);
            exit;
        }
        $stream = null;
        try {
            $id = Files::id(is_string($_POST['receipt'] ?? null) ? wp_unslash($_POST['receipt']) : '');
            $eventId = Files::id(is_string($_POST['event_id'] ?? null) ? wp_unslash($_POST['event_id']) : '');
            $sha256 = is_string($_POST['sha256'] ?? null) ? wp_unslash($_POST['sha256']) : '';
            check_admin_referer('wp_clamav_download_original_' . $id . '_' . $eventId . '_' . $sha256);
            $reason = is_string($_POST['reason'] ?? null) ? trim(sanitize_textarea_field(wp_unslash($_POST['reason']))) : '';
            if ($reason === '' || strlen($reason) > 1000 || ($_POST['confirmed'] ?? '') !== '1'
                || !preg_match('/\A[a-f0-9]{64}\z/D', $sha256)) {
                throw new \RuntimeException('An explicit review confirmation and reason are required.');
            }
            $store = Plugin::storage();
            $download = $store->withLock('scan-' . $id, fn(): array => $store->withAccounting(fn(): array =>
                $store->withLock('receipt-' . $id, function () use ($store, $id, $eventId, $sha256, $reason): array {
                $receipt = $store->get($id);
                $event = $store->event($eventId);
                if (($event['upload_id'] ?? '') !== $id || ($receipt['latest_event_id'] ?? null) !== $eventId
                    || !hash_equals((string) ($receipt['sha256'] ?? ''), $sha256)
                    || !AssetPolicy::eligible($event, $receipt, $store->latestEvent($id))) {
                    throw new \RuntimeException('The original no longer has a current eligible review result.');
                }
                $original = $store->open($id);
                try {
                    $hash = hash_init('sha256');
                    $size = hash_update_stream($hash, $original);
                    if ($size !== $receipt['size'] || !hash_equals($sha256, hash_final($hash))) {
                        throw new \RuntimeException('The preserved original failed its integrity check.');
                    }
                    rewind($original);
                    Files::json($store->directory() . '/audit/' . bin2hex(random_bytes(16)) . '.json', [
                        'action' => 'administrator_download_authorized', 'upload_id' => $id, 'event_id' => $eventId,
                        'sha256' => $sha256, 'size' => $size, 'scan_status' => $event['status'],
                        'actor' => get_current_user_id(), 'site_id' => get_current_blog_id(), 'reason' => $reason, 'created_at' => time(),
                    ], true);
                    if (!AssetPolicy::eligible($event, $store->get($id), $store->latestEvent($id))) {
                        throw new \RuntimeException('The review result changed before download authorization completed.');
                    }
                    return ['stream' => $original, 'name' => $receipt['name'], 'size' => $size];
                } catch (\Throwable $error) { fclose($original); throw $error; }
                }, true)), true);
            $stream = $download['stream'];
            if (headers_sent()) { throw new \RuntimeException('The download response could not be started safely.'); }
            nocache_headers();
            header('Cache-Control: private, no-store, no-cache, max-age=0');
            header('Referrer-Policy: no-referrer');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . $download['size']);
            header("Content-Disposition: attachment; filename=\"review-file\"; filename*=UTF-8''" . rawurlencode($download['name']));
            while (!feof($stream) && !connection_aborted()) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) { break; }
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- authorized original bytes.
            }
        } catch (\Throwable $error) {
            if (!headers_sent()) {
                wp_die(esc_html__('This original is unavailable for administrative download. Refresh its review page and check the current scan result.', 'wp-clamav'), '', ['response' => 403]);
            }
        } finally { if (is_resource($stream)) { fclose($stream); } }
        exit;
    }

    private function requireCapability(): void
    {
        if (!current_user_can(Settings::capability())) {
            wp_die(esc_html__('You are not allowed to administer upload evidence.', 'wp-clamav'), '', ['response' => 403]);
        }
    }

    private function redirect(string $tab, string $message, string $receipt = ''): never
    {
        $args = ['message' => substr($message, 0, 1200)];
        if ($receipt !== '') {
            $args['receipt'] = $receipt;
        }
        wp_safe_redirect(self::url($tab, $args));
        exit;
    }

    private function json(mixed $value): string
    {
        return (string) wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/token|secret|password|credential|authorization/i', $key)) {
                $value[$key] = '[redacted]';
            } else {
                $value[$key] = $this->redact($item);
            }
        }
        return $value;
    }

    private function date(mixed $value): string
    {
        return is_numeric($value) ? gmdate('Y-m-d H:i:s \U\T\C', (int) $value) : (string) $value;
    }
}
