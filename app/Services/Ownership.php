<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class Ownership
{
    public static function ownsItem(int $userId, mixed $itemId): bool
    {
        $id = self::posInt($itemId);
        if ($id === null) return false;
        return Db::first('SELECT id FROM items WHERE id = ? AND user_id = ?', [$id, $userId]) !== null;
    }

    public static function ownsItemLive(int $userId, mixed $itemId): bool
    {
        $id = self::posInt($itemId);
        if ($id === null) return false;
        return Db::first('SELECT id FROM items WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$id, $userId]) !== null;
    }

    public static function ownsTag(int $userId, mixed $tagId): bool
    {
        $id = self::posInt($tagId);
        if ($id === null) return false;
        return Db::first('SELECT id FROM tags WHERE id = ? AND user_id = ?', [$id, $userId]) !== null;
    }

    public static function ownsFolder(int $userId, mixed $folderId): bool
    {
        $id = self::posInt($folderId);
        if ($id === null) return false;
        return Db::first('SELECT id FROM folders WHERE id = ? AND user_id = ?', [$id, $userId]) !== null;
    }

    public static function canAttachTag(int $userId, mixed $itemId, int $tagId): bool
    {
        return self::ownsItemLive($userId, $itemId) && self::ownsTag($userId, $tagId);
    }

    public static function canDetachTag(int $userId, mixed $itemId, mixed $tagId): bool
    {
        $tid = self::posInt($tagId);
        if (!self::ownsItemLive($userId, $itemId) || $tid === null) return false;
        return Db::first('SELECT item_id FROM item_tags WHERE item_id = ? AND tag_id = ?', [(int)$itemId, $tid]) !== null;
    }

    public static function itemTags(int $itemId, int $userId): array
    {
        return Db::all(
            'SELECT t.* FROM tags t
             JOIN item_tags it ON it.tag_id = t.id
             JOIN items i ON i.id = it.item_id
             WHERE it.item_id = ? AND i.user_id = ? AND t.user_id = ?',
            [$itemId, $userId, $userId]
        );
    }

    private static function posInt(mixed $v): ?int
    {
        if (is_int($v)) return $v > 0 ? $v : null;
        if (is_string($v) && preg_match('/^\d+$/', $v)) { $i = (int)$v; return $i > 0 ? $i : null; }
        return null;
    }
}
