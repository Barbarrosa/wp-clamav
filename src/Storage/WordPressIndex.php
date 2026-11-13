<?php
declare(strict_types=1);
namespace WPClamAV\Storage;

final class WordPressIndex
{
    public function __construct(private object $db) {}
    public function install(): void
    {
        $table = $this->db->prefix . 'clamav_receipts';
        $sql = "CREATE TABLE $table (id char(32) NOT NULL, created_at bigint unsigned NOT NULL, status varchar(16) NOT NULL, bytes bigint unsigned NOT NULL, deleted_at bigint unsigned NULL, PRIMARY KEY (id), KEY created_at (created_at,id), KEY status (status)) " . $this->db->get_charset_collate() . ';';
        if (!function_exists('dbDelta')) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
        dbDelta($sql);
    }
    public function upsert(array $receipt): void
    {
        $result = $this->db->replace($this->db->prefix . 'clamav_receipts', ['id' => $receipt['id'], 'created_at' => $receipt['created_at'], 'status' => $receipt['status'], 'bytes' => $receipt['size'], 'deleted_at' => $receipt['deleted_at'] ?? null]);
        if ($result === false) { throw new StorageException('Original persisted, but its administration index could not be updated.'); }
    }
    public function ids(int $limit, int $offset): array
    {
        return $this->db->get_col($this->db->prepare('SELECT id FROM ' . $this->db->prefix . 'clamav_receipts WHERE deleted_at IS NULL ORDER BY created_at DESC,id DESC LIMIT %d OFFSET %d', $limit, $offset));
    }
}
