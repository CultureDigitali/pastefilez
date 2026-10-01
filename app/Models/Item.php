<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Item
{
    public static function find(int $id, ?int $userId = null): ?array
    {
        $sql = 'SELECT * FROM items WHERE id = ? AND deleted_at IS NULL';
        $params = [$id];
        if ($userId !== null) { $sql .= ' AND user_id = ?'; $params[] = $userId; }
        return Db::first($sql, $params);
    }

    public static function list(int $userId, int $limit = 60, int $offset = 0, ?int $folderId = null, ?string $type = null): array
    {
        $sql = 'SELECT * FROM items WHERE user_id = ? AND deleted_at IS NULL';
        $params = [$userId];
        if ($folderId !== null) { $sql .= ' AND folder_id = ?'; $params[] = $folderId; }
        if ($type) { $sql .= ' AND mime LIKE ?'; $params[] = \App\Services\SearchService::typeToMime($type) . '%'; }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        return Db::all($sql, $params);
    }
}
