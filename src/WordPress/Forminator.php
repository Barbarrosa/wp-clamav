<?php
declare(strict_types=1);

namespace WPClamAV\WordPress;

/** Forminator's uploader bypasses WordPress core upload hooks. */
final class Forminator
{
    public const QUALIFIED_VERSION = '1.58.4';
    private const COOKIE = 'wp_clamav_uploader';
    private array $requestReceipts = [];
    private array $requestWarnings = [];
    private array $nativeCache = [];
    private array $submissionIds = [];
    private array $fieldPolicyHolds = [];
    private array $tokenReceipts = [];
    private ?array $requestEnvelope = null;
    private int $renderForm = 0;
    private bool $assetEnqueued = false;

    public function register(): void
    {
        add_filter('forminator_handle_specific_field_types', [$this, 'field'], PHP_INT_MAX, 3);
        add_action('wp_ajax_forminator_multiple_file_upload', [$this, 'ajaxUpload'], -100);
        add_action('wp_ajax_nopriv_forminator_multiple_file_upload', [$this, 'ajaxUpload'], -100);
        add_action('forminator_custom_form_submit_before_set_fields', [$this, 'commitSubmission'], 1, 3);
        add_filter('forminator_entry_meta_value_to_string', [$this, 'formatReceipt'], 20, 5);
        add_filter('forminator_custom_form_mail_admin_message', [$this, 'mailMessage'], 20, 6);
        add_filter('forminator_custom_form_mail_attachment', [$this, 'mailAttachments'], PHP_INT_MAX, 4);
        add_filter('forminator_file_upload_allow', [$this, 'preventNativeFallback'], PHP_INT_MIN, 4);
        add_filter('forminator_cform_render_fields', [$this, 'renderFields'], 10, 2);
        add_filter('forminator_field_file_markup', [$this, 'fieldMarkup'], 20, 2);
        add_filter('forminator_field_file_validation_rules', [$this, 'validationRules'], PHP_INT_MAX, 3);
        add_filter('forminator_form_ajax_submit_response', [$this, 'response'], 20, 2);
        add_filter('forminator_form_submit_response', [$this, 'response'], 20, 2);
    }

    public function protects(int $formId): bool
    {
        $forms = Settings::get('forminator_forms', []);
        return Settings::enabled() && $formId > 0 && ($forms === [] || in_array($formId, $forms, true));
    }

    public function qualified(): bool
    {
        return defined('FORMINATOR_VERSION') && constant('FORMINATOR_VERSION') === self::QUALIFIED_VERSION;
    }

    /**
     * Supported early-return contract: never let process_uploads() see an upload
     * record for a protected field. Receipt acquisition is not scan approval.
     */
    public function field(mixed $data, mixed $object, array $field): mixed
    {
        if (($field['type'] ?? '') !== 'upload') {
            return $data;
        }
        $formId = $this->currentForm();
        if (!$this->protects($formId)) {
            return $data;
        }
        $fieldId = (string) ($field['element_id'] ?? '');
        $ids = [];
        $warnings = [];
        try {
            $multiple = ($field['file-type'] ?? 'single') === 'multiple';
            $ajax = $multiple && ($field['upload-method'] ?? 'ajax') === 'ajax';
            $holds = $this->requestPolicyHolds($formId);
            if ($ajax) {
                $uploads = is_array($data) ? ($data['file'] ?? []) : [];
                // Abandonment clears the vendor's per-field upload payload,
                // while its normalized hidden receipt map remains available.
                // Every reference still needs our secret/browser/site binding.
                if (class_exists('Forminator_CForm_Front_Action')) {
                    $references = \Forminator_CForm_Front_Action::$prepared_data['forminator-multifile-hidden'][$fieldId] ?? null;
                    if (is_array($references)) { $uploads = $references; }
                }
                foreach (is_array($uploads) ? $uploads : [] as $upload) {
                    if (!is_array($upload) || empty($upload['access_token'])) {
                        $warnings[] = 'An attachment was not received; the narrative can still be submitted.';
                        continue;
                    }
                    try {
                        $receipt = $this->resolveToken((string) $upload['access_token'], $formId, $fieldId);
                        $ids[] = $receipt['id'];
                    } catch (\Throwable $error) {
                        $warnings[] = 'An attachment reference could not be verified; the narrative can still be submitted.';
                    }
                }
            } else {
                $files = array_values(array_filter($this->normalizeFiles($_FILES[$fieldId] ?? []),
                    static fn(array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
                $fieldLimit = Plugin::app()->policy->fileCount($this->fieldCount($field));
                if ($fieldLimit !== null && count($files) > $fieldLimit) { $holds[] = 'field-file-count-exceeded'; }
                foreach ($files as $file) {
                    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    try {
                        $receipt = $this->receive($file, $formId, $fieldId, $field, null, $holds);
                        $ids[] = $receipt['id'];
                    } catch (\Throwable $error) {
                        $warnings[] = 'An attachment could not be preserved. Keep your original and contact the site administrator.';
                    }
                }
            }
            $ids = array_values(array_unique($ids));
            $countLimit = Plugin::app()->policy->fileCount($this->fieldCount($field));
            if ($countLimit !== null && count($ids) > $countLimit) {
                $holds[] = 'field-file-count-exceeded';
                $warnings[] = 'The configured attachment count was exceeded; received originals remain private for review.';
            }
            $this->fieldPolicyHolds[$formId][$fieldId] = array_values(array_unique($holds));
            if ($holds !== []) {
                $warnings[] = 'Attachments exceed a configured request limit or the request could not be verified; received originals remain private and are not scanned automatically.';
            }
            if (!empty($field['required']) && $ids === []) {
                $warnings[] = 'No attachment was received. Your written submission is independent of its attachments.';
            }
        } catch (\Throwable $error) {
            $warnings[] = 'Attachments remain unverified. Your written submission can still be submitted.';
        }
        if (!$this->qualified()) {
            $warnings[] = 'Attachments are held because this Forminator version has not been qualified.';
        }
        $this->requestReceipts[$formId][$fieldId] = $ids;
        $this->requestWarnings[$formId][$fieldId] = array_values(array_unique($warnings));

        // Clear the native prepared payload as well: add-ons must not receive
        // PHP temporary paths or a client-supplied path through this field.
        if (class_exists('Forminator_CForm_Front_Action')) {
            \Forminator_CForm_Front_Action::$prepared_data[$fieldId] = ['scanner_receipts' => $ids];
        }
        return [
            'return' => true,
            'name' => $fieldId,
            'field_type' => 'scanner_receipt',
            'value' => [
                'file' => ['file_url' => [], 'file_path' => [], 'file_name' => [], 'attachment_id' => []],
                'scanner_receipts' => $ids,
                'scanner_warnings' => array_values(array_unique($warnings)),
            ],
        ];
    }

    /** Takes over only protected forms; unprotected forms retain vendor behavior. */
    public function ajaxUpload(): void
    {
        $formId = isset($_POST['form_id']) ? (int) $_POST['form_id'] : 0;
        if (!$this->protects($formId)) {
            return;
        }
        try {
            $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? wp_unslash($_POST['nonce']) : '';
            if (!wp_verify_nonce($nonce, 'forminator_submit_form' . $formId)) {
                throw new \InvalidArgumentException('The upload session expired. Refresh the form and try again.');
            }
            $baseFieldId = isset($_POST['element_id']) && is_string($_POST['element_id']) ? sanitize_text_field(wp_unslash($_POST['element_id'])) : '';
            $fieldId = isset($_POST['wp_clamav_field']) && is_string($_POST['wp_clamav_field']) ? sanitize_text_field(wp_unslash($_POST['wp_clamav_field'])) : $baseFieldId;
            if (!preg_match('/^upload-[0-9]+(?:-[0-9]+)*$/D', $baseFieldId)
                || !preg_match('/^' . preg_quote($baseFieldId, '/') . '(?:-[0-9]+)*$/D', $fieldId)) {
                throw new \InvalidArgumentException('Invalid upload field.');
            }
            if (!class_exists('Forminator_API')) {
                throw new \RuntimeException('The form service is unavailable.');
            }
            $form = \Forminator_API::get_form($formId);
            if (is_wp_error($form) || !is_object($form) || !method_exists($form, 'get_field')) {
                throw new \InvalidArgumentException('This upload form is unavailable.');
            }
            $field = $form->get_field($baseFieldId, true);
            if (!is_array($field) || ($field['type'] ?? '') !== 'upload') {
                throw new \InvalidArgumentException('This upload field does not exist.');
            }
            $files = $this->normalizeFiles($_FILES[$baseFieldId] ?? []);
            if (count($files) !== 1) {
                throw new \InvalidArgumentException('Send one complete file per upload request.');
            }
            $secret = bin2hex(random_bytes(32));
            $holds = $this->requestPolicyHolds($formId);
            $countLimit = Plugin::app()->policy->fileCount($this->fieldCount($field));
            if ($countLimit !== null && count($files) > $countLimit) { $holds[] = 'field-file-count-exceeded'; }
            $receipt = $this->receive($files[0], $formId, $fieldId, $field, hash('sha256', $secret), $holds);
            wp_send_json_success([
                'success' => true,
                'message' => 'The original was received privately. This is not a scan approval.',
                'access_token' => $receipt['id'] . '.' . $secret,
                'scanner_status' => 'held',
            ]);
        } catch (\Throwable $error) {
            wp_send_json_error([
                'success' => false,
                'message' => $error instanceof \InvalidArgumentException ? $error->getMessage() : 'The attachment could not be preserved. Keep your original. You can still submit the written information.',
            ]);
        }
    }

    private function receive(array $file, int $formId, string $fieldId, array $field, ?string $tokenHash = null, array $holds = []): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || empty($file['tmp_name']) || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('The attachment was not fully received. Keep your original and try again.');
        }
        $cacheKey = $formId . ':' . $fieldId . ':' . $file['tmp_name'];
        if ($tokenHash === null && isset($this->nativeCache[$cacheKey])) {
            return $this->nativeCache[$cacheKey];
        }
        $name = (string) ($file['name'] ?? 'attachment');
        $size = filesize($file['tmp_name']);
        if ($size === false) {
            throw new \RuntimeException('The uploaded original cannot be read.');
        }
        $warnings = $holds;
        $cap = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : PHP_INT_MAX;
        $fieldLimit = (float) ($field['upload-limit'] ?? 0);
        $units = match ($field['filesize'] ?? 'MB') { 'B' => 1, 'KB' => 1024, default => 1048576 };
        if ($fieldLimit > 0) {
            $cap = min($cap, (int) floor($fieldLimit * $units));
        }
        if ($size > $cap) {
            $warnings[] = 'field-size-limit-exceeded';
        }
        if ($size === 0) {
            $warnings[] = 'empty-original';
        }
        // A disallowed type is retained as evidence, rather than making the
        // complaint's narrative fail validation. It never becomes scan-approved.
        $type = wp_check_filetype_and_ext($file['tmp_name'], $name);
        if (empty($type['ext']) || empty($type['type'])) {
            $warnings[] = 'unverified-file-type';
        }
        $allowed = function_exists('forminator_allowed_mime_types') ? forminator_allowed_mime_types([], false) : [];
        if (filter_var($field['custom-files'] ?? false, FILTER_VALIDATE_BOOLEAN) && class_exists('Forminator_Core')) {
            $upload = \Forminator_Core::get_field_object('upload');
            if (is_object($upload) && method_exists($upload, 'file_mime_type')) {
                $allowed = $upload->file_mime_type($field);
            }
        }
        $allowed = apply_filters('forminator_upload_field_mime_types', $allowed, $field);
        if (!is_array($allowed) || empty(wp_check_filetype($name, $allowed)['ext'])) { $warnings[] = 'field-type-limit-exceeded'; }
        $receipt = Plugin::intake()->receivePath($file['tmp_name'], [
            'name' => $name,
            'origin' => 'forminator',
            'site_id' => get_current_blog_id(),
            'form_id' => $formId,
            'field_id' => $fieldId,
            'user_id' => get_current_user_id(),
            'browser_hash' => $this->browserHash(),
            'receipt_token_hash' => $tokenHash,
            'field_limit' => $cap,
            'adapter_version' => defined('FORMINATOR_VERSION') ? (string) FORMINATOR_VERSION : 'unknown',
            'adapter_qualified' => $this->qualified(),
            'intake_holds' => $warnings,
            'scan_requires_submission' => true,
        ]);
        $this->nativeCache[$cacheKey] = $receipt;
        return $receipt;
    }

    private function resolveToken(string $token, int $formId, string $fieldId): array
    {
        if (!preg_match('/^([A-Za-z0-9_-]{1,128})\.([a-f0-9]{64})$/D', $token, $matches)) {
            throw new \InvalidArgumentException('Invalid private receipt.');
        }
        $receipt = $this->tokenReceipts[$matches[1]] ??= Plugin::intake()->get($matches[1]);
        $context = $receipt['context'] ?? [];
        if (($context['origin'] ?? '') !== 'forminator' || (int) ($context['site_id'] ?? 0) !== get_current_blog_id()
            || (int) ($context['form_id'] ?? 0) !== $formId || ($context['field_id'] ?? '') !== $fieldId
            || !is_string($context['receipt_token_hash'] ?? null)
            || !hash_equals($context['receipt_token_hash'], hash('sha256', $matches[2]))
            || !hash_equals((string) ($context['browser_hash'] ?? ''), $this->browserHash(false))) {
            throw new \InvalidArgumentException('The private receipt does not belong to this submission session.');
        }
        return $receipt;
    }

    private function browserHash(bool $create = true): string
    {
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($cookie) || !preg_match('/^[a-f0-9]{64}$/D', $cookie)) {
            if (!$create) {
                return '';
            }
            if (headers_sent()) {
                throw new \RuntimeException('A private upload session could not be established.');
            }
            $cookie = bin2hex(random_bytes(32));
            setcookie(self::COOKIE, $cookie, [
                'expires' => 0, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
            ]);
            $_COOKIE[self::COOKIE] = $cookie;
        }
        return hash_hmac('sha256', $cookie, wp_salt('auth'));
    }

    /** Preserve every validated narrative independently of Forminator retention. */
    public function commitSubmission(mixed $entry, int $formId, array $fields): void
    {
        if (!$this->protects($formId)) {
            return;
        }
        $entryId = is_object($entry) ? (string) ($entry->entry_id ?? '') : '';
        $key = $formId . ':' . $entryId;
        $safe = [];
        $ids = [];
        $scanIds = [];
        $lifecycleHolds = $this->lifecycleHolds($entry);
        // Draft/abandonment processing may omit upload fields entirely. Preserve
        // their genuine originals/references without treating that omission as
        // successful field validation or permission to scan.
        $fields = array_merge($fields, $this->preserveSkippedUploads($formId));
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['name'])) {
                continue;
            }
            $name = (string) $field['name'];
            $value = $this->dataOnly($field['value'] ?? null);
            $safe[] = ['name' => $name, 'value' => $value];
        }
        // Receipt membership and scan eligibility come only from our server-side
        // field processing. Client field values/warnings never grant native work.
        foreach ($this->requestReceipts[$formId] ?? [] as $fieldId => $fieldIds) {
            foreach ($fieldIds as $id) {
                $ids[] = $id;
                $receipt = Plugin::intake()->get($id);
                $context = $receipt['context'] ?? [];
                if ($lifecycleHolds === [] && $this->qualified() && ($this->fieldPolicyHolds[$formId][$fieldId] ?? []) === []
                    && ($context['intake_holds'] ?? []) === [] && ($context['adapter_qualified'] ?? false) === true
                    && ($receipt['complete'] ?? false) === true && !isset($receipt['deleted_at']) && ($receipt['size'] ?? 0) > 0
                    && ($context['origin'] ?? '') === 'forminator' && (int) ($context['site_id'] ?? 0) === get_current_blog_id()
                    && (int) ($context['form_id'] ?? 0) === $formId && ($context['field_id'] ?? '') === $fieldId) {
                    $scanIds[] = $id;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        $countLimit = Plugin::app()->policy->fileCount();
        if ($this->requestPolicyHolds($formId) !== [] || ($countLimit !== null && count($ids) > $countLimit)) { $scanIds = []; }
        $scanIds = array_values(array_unique($scanIds));
        $revision = substr(hash('sha256', (string) wp_json_encode([$safe, $ids, $scanIds, $lifecycleHolds])), 0, 16);
        $submissionId = $this->submissionIds[$key . ':' . $revision] ??= ($entryId !== '' && $entryId !== '0'
            ? 'forminator_' . get_current_blog_id() . '_' . $formId . '_' . $entryId . '_' . $revision
            : 'forminator_' . bin2hex(random_bytes(16)));
        Plugin::storage()->commitSubmission($submissionId, [
            'origin' => 'forminator', 'site_id' => get_current_blog_id(), 'form_id' => $formId,
            'forminator_entry_id' => $entryId, 'fields' => $safe,
            'forminator_entry_status' => is_object($entry) ? (string) ($entry->status ?? '') : '',
            'scan_lifecycle_holds' => $lifecycleHolds,
            'scan_receipt_ids' => $scanIds,
        ], $ids);
        foreach ($scanIds as $id) {
            try { Plugin::scheduleScan($id, null, false, null, $submissionId); }
            catch (\Throwable) {
                // A queue lock/storage failure cannot undo the already durable
                // narrative or prevent Forminator's independent notification.
                $this->requestWarnings[$formId]['dispatch'][] = 'The original remains private, but its scan could not be queued. Contact the site administrator.';
                error_log('WP ClamAV scan intent needs administrative recovery for receipt ' . $id);
            }
        }
    }

    private function preserveSkippedUploads(int $formId): array
    {
        $records = [];
        $prepared = class_exists('Forminator_CForm_Front_Action') ? \Forminator_CForm_Front_Action::$prepared_data : [];
        $tokens = $prepared['forminator-multifile-hidden'] ?? [];
        $tokens = is_array($tokens) ? $tokens : [];
        $fieldIds = array_unique(array_merge(array_keys($_FILES), array_keys($tokens)));
        if ($fieldIds === [] || !class_exists('Forminator_API')) { return []; }
        $form = \Forminator_API::get_form($formId);
        if (is_wp_error($form) || !is_object($form) || !method_exists($form, 'get_field')) { return []; }
        foreach ($fieldIds as $fieldId) {
            if (!is_string($fieldId) || !preg_match('/^upload-[0-9]+(?:-[0-9]+)*$/D', $fieldId)
                || array_key_exists($fieldId, $this->requestReceipts[$formId] ?? [])) { continue; }
            $field = $form->get_field($fieldId, true);
            if (!is_array($field) || ($field['type'] ?? '') !== 'upload') { continue; }
            $field['element_id'] = $fieldId;
            $record = $this->field(['file' => $tokens[$fieldId] ?? []], null, $field);
            $this->fieldPolicyHolds[$formId][$fieldId][] = 'forminator-upload-field-not-processed';
            if (is_array($record)) { unset($record['return']); $records[] = $record; }
        }
        return $records;
    }

    /**
     * Qualified 1.58.4 saves drafts, abandonment, spam and pending payments through
     * this same hook. Only its accepted final `active` lifecycle grants work.
     * Preview models also default to `active`, so status alone is insufficient.
     * Prepared request flags can only withhold work; they never grant approval.
     */
    private function lifecycleHolds(mixed $entry): array
    {
        $holds = [];
        if (!is_object($entry) || ($entry->status ?? null) !== 'active') {
            $holds[] = 'forminator-entry-not-active';
        }
        if (is_object($entry) && !empty($entry->is_spam)) { $holds[] = 'forminator-entry-spam'; }
        $prepared = class_exists('Forminator_CForm_Front_Action') ? \Forminator_CForm_Front_Action::$prepared_data : [];
        if (filter_var($prepared['save_draft'] ?? false, FILTER_VALIDATE_BOOLEAN)) { $holds[] = 'forminator-draft'; }
        if (!empty($prepared['abandoned_form'])) { $holds[] = 'forminator-abandoned'; }
        $preview = 'forminator_submit_preview_form_custom-forms';
        if (($prepared['action'] ?? '') === $preview || doing_action('wp_ajax_' . $preview)
            || doing_action('wp_ajax_nopriv_' . $preview)) {
            $holds[] = 'forminator-preview';
        }
        return $holds;
    }

    private function fieldCount(array $field): ?int
    {
        if (($field['file-type'] ?? 'single') !== 'multiple') { return 1; }
        return ($field['file-limit'] ?? 'unlimited') === 'custom' ? max(0, (int) ($field['file-limit-input'] ?? 5)) : null;
    }

    /** Measure the accepted PHP upload envelope, never client totalFiles/size fields. */
    private function requestPolicyHolds(int $formId): array
    {
        if ($this->requestEnvelope !== null) { return $this->requestEnvelope['holds']; }
        $count = 0; $bytes = 0; $seen = [];
        foreach ($_FILES as $key => $entry) {
            foreach ($this->normalizeFiles($entry) as $index => $file) {
                if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
                $path = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
                $identity = $path !== '' ? $path : $key . ':' . $index;
                if (isset($seen[$identity])) { continue; }
                $seen[$identity] = true; ++$count;
                if ($path !== '' && is_uploaded_file($path)) {
                    $size = filesize($path);
                    if (is_int($size) && $size >= 0 && $bytes <= PHP_INT_MAX - $size) { $bytes += $size; }
                }
            }
        }
        // Forminator has already decoded and normalized the hidden AJAX map.
        // Count only distinct receipts whose secret, browser, field and form
        // bindings verify; client counts and eligibility flags are irrelevant.
        $tokens = class_exists('Forminator_CForm_Front_Action')
            ? (\Forminator_CForm_Front_Action::$prepared_data['forminator-multifile-hidden'] ?? []) : [];
        foreach (is_array($tokens) ? $tokens : [] as $fieldId => $uploads) {
            if (!is_string($fieldId) || !preg_match('/^upload-[0-9]+(?:-[0-9]+)*$/D', $fieldId)) { continue; }
            foreach (is_array($uploads) ? $uploads : [] as $upload) {
                if (!is_array($upload) || !is_string($upload['access_token'] ?? null)) { continue; }
                try {
                    $receipt = $this->resolveToken($upload['access_token'], $formId, $fieldId);
                    $identity = 'receipt:' . $receipt['id'];
                    if (!isset($seen[$identity])) { $seen[$identity] = true; ++$count; }
                } catch (\Throwable) { /* An unverified token cannot grant work or impersonate a received file. */ }
            }
        }
        $holds = [];
        $policy = Plugin::app()->policy;
        $limit = $policy->fileCount();
        if ($limit !== null && $count > $limit) { $holds[] = 'request-file-count-exceeded'; }
        $nonfile = $this->postBytes(wp_unslash($_POST));
        $length = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($length === false || $nonfile > $length || $bytes > $length - $nonfile) {
            $holds[] = 'request-framing-unverified';
        } else {
            $available = $policy->requestLimit($nonfile, $length - $nonfile - $bytes);
            if ($available !== null && $bytes > $available) { $holds[] = 'request-body-limit-exceeded'; }
        }
        $this->requestEnvelope = ['count' => $count, 'bytes' => $bytes, 'holds' => $holds];
        return $holds;
    }

    private function postBytes(array $values): int
    {
        $bytes = 0;
        foreach ($values as $key => $value) {
            $bytes += strlen((string) $key);
            $bytes += is_array($value) ? $this->postBytes($value) : strlen((string) $value);
        }
        return $bytes;
    }

    private function dataOnly(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            throw new \InvalidArgumentException('The submission contains excessively nested data.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->dataOnly($item, $depth + 1);
            }
            return $value;
        }
        return is_scalar($value) || $value === null ? $value : null;
    }

    public function formatReceipt(mixed $rendered, string $type, mixed $value, bool $html = false, int $truncate = 0): mixed
    {
        if (!is_array($value) || !isset($value['scanner_receipts'])) {
            return $rendered;
        }
        $lines = [];
        foreach ((array) $value['scanner_receipts'] as $id) {
            try {
                $receipt = Plugin::intake()->get((string) $id);
                $lines[] = (string) ($receipt['name'] ?? 'Attachment') . ' — received privately; status: ' . (string) ($receipt['status'] ?? 'held') . '; receipt ' . $id;
            } catch (\Throwable $error) {
                $lines[] = 'Private attachment receipt ' . (string) $id . ' — status unavailable.';
            }
        }
        foreach ((array) ($value['scanner_warnings'] ?? []) as $warning) {
            if (is_string($warning)) {
                $lines[] = $warning;
            }
        }
        $text = implode("\n", $lines);
        return $html ? nl2br(esc_html($text)) : $text;
    }

    public function mailMessage(string $message, mixed $form, mixed $data = null, mixed $entry = null, mixed $mailer = null, mixed $notification = null): string
    {
        $formId = is_object($form) ? (int) ($form->id ?? 0) : 0;
        if (!$this->protects($formId)) {
            return $message;
        }
        return $message . '<p>Attachment originals are preserved privately and are not attached to this narrative notification. Scan completion and attachment authorization are separate decisions.</p>';
    }

    public function mailAttachments(mixed $attachments, mixed $form = null, mixed $entry = null, mixed $pdfs = null): mixed
    {
        $formId = is_object($form) ? (int) ($form->id ?? 0) : 0;
        return $this->protects($formId) ? [] : $attachments;
    }

    /** Backstop for changed/unknown vendor paths: never permit a native public move. */
    public function preventNativeFallback(mixed $allow, mixed $fieldName = null, mixed $fileName = null, mixed $valid = null): mixed
    {
        $formId = $this->currentForm();
        if ($formId === 0 && isset($_POST['form_id'])) {
            $formId = (int) $_POST['form_id'];
        }
        return $this->protects($formId) ? false : $allow;
    }

    public function renderFields(mixed $wrappers, int $formId): mixed
    {
        $this->renderForm = $formId;
        if ($this->protects($formId) && !$this->assetEnqueued) {
            wp_enqueue_script('wp-clamav-forminator', plugins_url('assets/forminator.js', WP_CLAMAV_PLUGIN_FILE), ['jquery'], WP_CLAMAV_VERSION, true);
            wp_localize_script('wp-clamav-forminator', 'WPClamAVForminator', ['forms' => Settings::get('forminator_forms', []), 'qualified' => $this->qualified()]);
            $this->assetEnqueued = true;
        }
        return $wrappers;
    }

    public function fieldMarkup(string $html, array $field): string
    {
        if (!$this->protects($this->renderForm)) {
            return $html;
        }
        return $html . '<p class="wp-clamav-upload-note">Attachments are received into private storage. A completed upload does not mean scan approval. You may submit the written information even if an attachment cannot be received.</p>';
    }

    public function validationRules(string $rules, string $id, array $field): string
    {
        return $this->protects($this->renderForm) ? wp_json_encode($id) . ': {},' . "\n" : $rules;
    }

    public function response(mixed $response, int $formId): mixed
    {
        if (!$this->protects($formId) || !is_array($response) || empty($response['success'])) {
            return $response;
        }
        if (!empty($this->requestReceipts[$formId]) || !empty($this->requestWarnings[$formId])) {
            $ids = [];
            $warnings = [];
            foreach ($this->requestReceipts[$formId] ?? [] as $fieldIds) {
                $ids = array_merge($ids, $fieldIds);
            }
            foreach ($this->requestWarnings[$formId] ?? [] as $fieldWarnings) {
                $warnings = array_merge($warnings, $fieldWarnings);
            }
            $message = ' Your written information has been received.';
            if ($ids !== []) {
                $message .= ' Received attachments are retained privately pending scan and access decisions.';
            }
            if ($warnings !== []) {
                $message .= ' Some attachments were not received or could not be verified. Keep your originals and contact the site administrator if you need help.';
            }
            $response['message'] = (string) ($response['message'] ?? '') . $message;
        }
        return $response;
    }

    private function currentForm(): int
    {
        return class_exists('Forminator_CForm_Front_Action') ? (int) \Forminator_CForm_Front_Action::$module_id : 0;
    }

    private function normalizeFiles(mixed $file): array
    {
        if (!is_array($file) || !isset($file['name'])) {
            return [];
        }
        if (!is_array($file['name'])) {
            return [$file];
        }
        $out = [];
        foreach ($file['name'] as $index => $name) {
            $item = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size', 'full_path'] as $key) {
                if (isset($file[$key]) && is_array($file[$key])) {
                    $item[$key] = $file[$key][$index] ?? null;
                }
            }
            if (is_array($name)) {
                $out = array_merge($out, $this->normalizeFiles($item));
            } else {
                $out[] = $item;
            }
        }
        return $out;
    }
}
