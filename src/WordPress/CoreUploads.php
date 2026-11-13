<?php
declare(strict_types=1);

namespace WPClamAV\WordPress;

final class CoreUploads
{
    private array $approved = [];
    public function register(): void
    {
        add_filter('wp_handle_upload_prefilter', [$this, 'file'], PHP_INT_MAX);
        add_filter('wp_handle_sideload_prefilter', [$this, 'file'], PHP_INT_MAX);
        add_filter('wp_upload_bits', [$this, 'bits'], PHP_INT_MAX);
        add_filter('pre_move_uploaded_file', [$this, 'beforeMove'], PHP_INT_MIN, 4);
    }

    public function file(array $file): array
    {
        if (!Settings::enabled() || !empty($file['error'])) {
            return $file;
        }
        try {
            $receipt = Plugin::intake()->receivePath((string) ($file['tmp_name'] ?? ''), [
                'name' => (string) ($file['name'] ?? 'upload'),
                'origin' => 'wordpress',
                'site_id' => get_current_blog_id(),
                'user_id' => get_current_user_id(),
            ]);
            $result = Plugin::intake()->assess($receipt['id']);
            if (!Plugin::intake()->canPublish($receipt['id'])) {
                $file['error'] = $this->heldMessage($receipt['id'], (string) ($result['status'] ?? 'held'));
            } else {
                $this->approved[(string) ($file['tmp_name'] ?? '')] = ['id' => $receipt['id'], 'sha256' => $receipt['sha256']];
            }
        } catch (\Throwable $error) {
            $file['error'] = __('The upload could not be verified. It was not published. Please contact the site administrator.', 'wp-clamav');
        }
        return $file;
    }

    /** Covers custom _wp_handle_upload actions without abusing its short-circuit value. */
    public function beforeMove(mixed $move, array $file, string $destination, string $type): mixed
    {
        if (!Settings::enabled()) {
            return $move;
        }
        $path = (string) ($file['tmp_name'] ?? '');
        $proof = $this->approved[$path] ?? null;
        $hash = $path !== '' && is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
        if (is_array($proof) && is_string($hash) && hash_equals((string) $proof['sha256'], $hash)
            && Plugin::intake()->canPublish((string) $proof['id'])) {
            return $move;
        }
        $checked = $this->file(array_replace($file, ['error' => 0]));
        if (empty($checked['error'])) {
            return $move;
        }
        // Non-null pre_move_uploaded_file return values skip BOTH the move and
        // WordPress's error handling. A rejection must terminate before the move.
        wp_die(esc_html((string) $checked['error']), esc_html__('Upload held', 'wp-clamav'), ['response' => 503]);
        return $move;
    }

    public function bits(mixed $upload): mixed
    {
        if (!Settings::enabled() || !is_array($upload)) {
            return $upload;
        }
        $stream = null;
        try {
            if (!isset($upload['bits']) || !is_string($upload['bits'])) {
                throw new \InvalidArgumentException('Invalid upload bytes.');
            }
            $stream = fopen('php://temp/maxmemory:131072', 'w+b');
            if ($stream === false) {
                throw new \RuntimeException('Temporary stream unavailable.');
            }
            $length = strlen($upload['bits']);
            for ($offset = 0; $offset < $length; $offset += 65536) {
                $chunk = substr($upload['bits'], $offset, 65536);
                if (fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Temporary stream write failed.');
                }
            }
            rewind($stream);
            $receipt = Plugin::intake()->receiveStream($stream, [
                'name' => (string) ($upload['name'] ?? 'upload'), 'origin' => 'wordpress-bits',
                'site_id' => get_current_blog_id(), 'user_id' => get_current_user_id(),
            ]);
            $result = Plugin::intake()->assess($receipt['id']);
            return Plugin::intake()->canPublish($receipt['id']) ? $upload : $this->heldMessage($receipt['id'], (string) ($result['status'] ?? 'held'));
        } catch (\Throwable $error) {
            return __('The upload could not be verified. It was not published. Please contact the site administrator.', 'wp-clamav');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function heldMessage(string $id, string $status): string
    {
        return sprintf(
            /* translators: 1: upload receipt, 2: scan status */
            __('The original was preserved privately as receipt %1$s (%2$s). It has not been published.', 'wp-clamav'),
            $id,
            $status
        );
    }
}
