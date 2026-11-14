<?php
/** Run using wp eval-file after installing the disposable WordPress site. */
if (!defined('WP_CLAMAV_INTEGRATION_TEST') || WP_CLAMAV_INTEGRATION_TEST !== true) {
    throw new RuntimeException('Refusing to configure a non-test site.');
}
if (rtrim((string) realpath(ABSPATH), '/') !== '/workspace/wp-integration-site' || is_multisite()
    || get_option('siteurl') !== 'http://127.0.0.1:8081' || get_option('home') !== 'http://127.0.0.1:8081') {
    throw new RuntimeException('Refusing to reset operational fixture state outside the exact disposable site.');
}
// Each test run starts a fresh simulated account epoch. Remove only operational
// CPU/virtual-clock state left by earlier virtual-time runs; keep every canonical
// original, receipt, event, audit and submission. Production never performs this reset.
$cpuFixture = '/workspace/wp-integration-private/cpu-budget.json';
if (is_file($cpuFixture)) {
    new \WPClamAV\Storage\FileSubmissionStore('/workspace/wp-integration-private', [ABSPATH]);
    \WPClamAV\Storage\Files::track($cpuFixture, static fn(): bool => unlink($cpuFixture));
}
if (is_file('/workspace/wp-integration-clock.json')) { unlink('/workspace/wp-integration-clock.json'); }
wp_set_current_user(1);
$subscriber = get_user_by('login', 'integration-subscriber');
if (!$subscriber) {
    $subscriberId = wp_create_user('integration-subscriber', 'integration-only-subscriber-password', 'subscriber@example.test');
    if (is_wp_error($subscriberId)) { throw new RuntimeException($subscriberId->get_error_message()); }
    (new WP_User($subscriberId))->set_role('subscriber');
}
$forms = [];
$pages = [];
foreach (['single', 'submission', 'ajax', 'twofields', 'typeblocked'] as $mode) {
    $field = ['element_id' => 'upload-1', 'type' => 'upload', 'field_label' => 'Evidence', 'required' => true,
        'file-type' => in_array($mode, ['single', 'typeblocked'], true) ? 'single' : 'multiple', 'upload-method' => $mode === 'ajax' ? 'ajax' : 'submission',
        'upload-limit' => 8, 'filesize' => 'MB', 'file-limit' => 'custom', 'file-limit-input' => 3, 'use_library' => true, 'cols' => '12'];
    if ($mode === 'typeblocked') { $field['custom-files'] = true; $field['filetypes'] = ['pdf']; }
    $wrappers = [
        ['wrapper_id' => 'wrapper-1111111111111-1111', 'fields' => [['element_id' => 'text-1', 'type' => 'text', 'field_label' => 'Narrative', 'required' => true, 'cols' => '12']]],
        ['wrapper_id' => 'wrapper-2222222222222-2222', 'fields' => [$field]],
    ];
    if ($mode === 'twofields') {
        $other = $field; $other['element_id'] = 'upload-2';
        $wrappers[] = ['wrapper_id' => 'wrapper-3333333333333-3333', 'fields' => [$other]];
    }
    $id = Forminator_API::add_form('Private evidence integration ' . $mode, $wrappers,
        ['formName' => 'Private evidence integration ' . $mode, 'store_submissions' => '1', 'ajax' => true,
            // Exercise the qualified vendor's real draft/abandonment lifecycle;
            // enabling these features does not approve their attachments for scans.
            'use_save_and_continue' => $mode === 'ajax', 'abandonment' => $mode === 'ajax',
            'akismet-protection-behavior' => 'save',
            'validation' => 'on_submit', 'enable-ajax' => 'true', 'submitData' => ['custom-submit-text' => 'Send']]);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $form = Forminator_API::get_form($id);
    $form->notifications = [[
        'slug' => 'notification-integration', 'label' => 'Captured narrative',
        'email-recipients' => 'default', 'recipients' => 'narrative@example.test',
        'email-subject' => 'Integration narrative {submission_id}', 'email-editor' => '{all_fields}',
        'email-attachment' => 'true', 'fill-empty-fields' => 'true', 'type' => 'default',
    ]];
    $form->save();
    $forms[$mode] = $id;
    $pages[$mode] = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'Integration form ' . $mode, 'post_content' => '[forminator_form id="' . $id . '"]']);
}
$settings = \WPClamAV\WordPress\Settings::defaults();
$settings = array_replace($settings, ['enabled' => true, 'storage_path' => '/workspace/wp-integration-private',
    'backend' => 'local', 'profile' => 'full', 'forminator_forms' => array_values($forms), 'notification_rules' => [],
    'budgets' => [
        'storage_bytes' => 268435456, 'storage_reserve_bytes' => 16777216, 'update_reserve_bytes' => 16777216,
        // This explicitly configured disposable fixture starts a fresh test observation on each setup; it is not a production default.
        'account_remaining_bytes' => 268435456, 'inodes_remaining' => 10000, 'inode_reserve' => 100, 'quota_observed_at' => time(),
        'native_as_bytes' => 268435456, 'native_process_bytes' => 536870912, 'native_account_available_bytes' => 536870912,
        'request_work_seconds' => 20, 'worker_wall_seconds' => 20, 'cleanup_seconds' => 2,
        'native_cpu_seconds' => 10, 'native_file_bytes' => 16777216, 'php_work_bytes' => 67108864,
        'cpu_hourly_seconds' => 10000, 'cpu_daily_seconds' => 100000, 'cpu_monthly_seconds' => 1000000,
        'files_per_request' => 3, 'request_bytes' => 4096,
        'mail_recipient_hourly' => 100, 'mail_provider_hourly' => 400, 'mail_reserve_hourly' => 100, 'other_mail_hourly' => 100,
    ]]);
\WPClamAV\WordPress\Settings::save($settings);
update_option('wp_clamav_test_forms', $forms, false);
$password = WP_Application_Passwords::create_new_application_password(1, ['name' => 'wp-clamav-http-tests']);
file_put_contents('/workspace/wp-integration-fixture.json', json_encode(['forms' => $forms, 'pages' => $pages, 'application_password' => $password[0]], JSON_PRETTY_PRINT));
echo json_encode(['forms' => $forms, 'configured' => true]) . "\n";
