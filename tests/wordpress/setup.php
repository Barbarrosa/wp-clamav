<?php
/** Run using wp eval-file after installing the disposable WordPress site. */
if (!defined('WP_CLAMAV_INTEGRATION_TEST') || WP_CLAMAV_INTEGRATION_TEST !== true) {
    throw new RuntimeException('Refusing to configure a non-test site.');
}
wp_set_current_user(1);
$subscriber = get_user_by('login', 'integration-subscriber');
if (!$subscriber) {
    $subscriberId = wp_create_user('integration-subscriber', 'integration-only-subscriber-password', 'subscriber@example.test');
    if (is_wp_error($subscriberId)) { throw new RuntimeException($subscriberId->get_error_message()); }
    (new WP_User($subscriberId))->set_role('subscriber');
}
$forms = [];
$pages = [];
foreach (['single', 'submission', 'ajax'] as $mode) {
    $field = ['element_id' => 'upload-1', 'type' => 'upload', 'field_label' => 'Evidence', 'required' => true,
        'file-type' => $mode === 'single' ? 'single' : 'multiple', 'upload-method' => $mode === 'single' ? 'submission' : $mode,
        'upload-limit' => 8, 'filesize' => 'MB', 'file-limit' => 'custom', 'file-limit-input' => 3, 'use_library' => true, 'cols' => '12'];
    $wrappers = [
        ['wrapper_id' => 'wrapper-1111111111111-1111', 'fields' => [['element_id' => 'text-1', 'type' => 'text', 'field_label' => 'Narrative', 'required' => true, 'cols' => '12']]],
        ['wrapper_id' => 'wrapper-2222222222222-2222', 'fields' => [$field]],
    ];
    $id = Forminator_API::add_form('Private evidence integration ' . $mode, $wrappers,
        ['formName' => 'Private evidence integration ' . $mode, 'store_submissions' => '1', 'ajax' => true,
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
        'account_remaining_bytes' => 268435456, 'inodes_remaining' => 10000, 'inode_reserve' => 100,
        'native_as_bytes' => 268435456, 'native_process_bytes' => 536870912, 'native_account_available_bytes' => 536870912,
        'request_work_seconds' => 20, 'worker_wall_seconds' => 20, 'cleanup_seconds' => 2,
        'native_cpu_seconds' => 10, 'native_file_bytes' => 16777216, 'php_work_bytes' => 67108864,
        'mail_recipient_hourly' => 100, 'mail_provider_hourly' => 400, 'mail_reserve_hourly' => 100, 'other_mail_hourly' => 100,
    ]]);
\WPClamAV\WordPress\Settings::save($settings);
update_option('wp_clamav_test_forms', $forms, false);
$password = WP_Application_Passwords::create_new_application_password(1, ['name' => 'wp-clamav-http-tests']);
file_put_contents('/workspace/wp-integration-fixture.json', json_encode(['forms' => $forms, 'pages' => $pages, 'application_password' => $password[0]], JSON_PRETTY_PRINT));
echo json_encode(['forms' => $forms, 'configured' => true]) . "\n";
