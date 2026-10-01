<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Db;
use App\Models\Item;

final class ShareService
{
    public static function create(int $itemId, int $userId, ?string $password, ?int $expiresAt, ?int $maxViews): array
    {
        $item = Db::first('SELECT * FROM items WHERE id = ? AND user_id = ?', [$itemId, $userId]);
        if (!$item) return ['ok' => false, 'error' => 'not_found'];
        $token = Crypto::randomToken(24);
        $pwd = $password ? password_hash($password, PASSWORD_BCRYPT) : null;
        Db::update('items', [
            'is_public' => 1,
            'share_token' => $token,
            'share_password_hash' => $pwd,
            'share_expires_at' => $expiresAt,
            'share_max_views' => $maxViews,
            'share_view_count' => 0,
            'updated_at' => time(),
        ], 'id = ?', [$itemId]);
        return ['ok' => true, 'token' => $token, 'url' => url('s/' . $token)];
    }

    public static function revoke(int $itemId, int $userId): bool
    {
        $r = Db::update('items', [
            'is_public' => 0,
            'share_token' => null,
            'share_password_hash' => null,
            'share_expires_at' => null,
            'share_max_views' => null,
            'share_view_count' => 0,
            'updated_at' => time(),
        ], 'id = ? AND user_id = ?', [$itemId, $userId]);
        return $r > 0;
    }

    public static function get(string $token): ?array
    {
        $row = Db::first('SELECT i.*, u.email AS owner_email FROM items i JOIN users u ON u.id = i.user_id WHERE i.share_token = ? AND i.deleted_at IS NULL', [$token]);
        return $row ?: null;
    }
}
