<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Services\Ownership;

final class TagController extends Controller
{
    private const NAME_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N} _.-]{0,39}$/u';

    public function create(): void
    {
        $user = Auth::require();
        $name = self::normalizeTag(Request::inputString('name'));
        $color = Request::inputString('color');
        if ($name === null) Response::json(['ok' => false, 'error' => 'invalid']);
        $exists = Db::first('SELECT id FROM tags WHERE user_id = ? AND name = ?', [$user['id'], $name]);
        if ($exists) { Response::json(['ok' => true, 'id' => (int)$exists['id']]); }
        $id = Db::insert('tags', [
            'user_id' => $user['id'],
            'name' => $name,
            'color' => $color,
            'created_at' => time(),
        ]);
        Response::json(['ok' => true, 'id' => $id]);
    }

    public function attach(array $params): void
    {
        $user = Auth::require();
        $itemId = (int)($params['id'] ?? 0);
        if (!Ownership::ownsItemLive((int)$user['id'], $itemId)) Response::json(['ok' => false, 'error' => 'not_found'], 404);
        $tag = self::normalizeTag(Request::inputString('tag'));
        if ($tag === null) Response::json(['ok' => false, 'error' => 'invalid']);
        $tagRow = Db::first('SELECT * FROM tags WHERE user_id = ? AND name = ?', [$user['id'], $tag]);
        if (!$tagRow) {
            $tid = Db::insert('tags', ['user_id' => $user['id'], 'name' => $tag, 'color' => '', 'created_at' => time()]);
            $tagRow = ['id' => $tid];
        }
        if (!Ownership::canAttachTag((int)$user['id'], $itemId, (int)$tagRow['id'])) {
            Response::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        Db::run('INSERT OR IGNORE INTO item_tags(item_id, tag_id) VALUES(?,?)', [$itemId, (int)$tagRow['id']]);
        Response::json(['ok' => true]);
    }

    public function detach(array $params): void
    {
        $user = Auth::require();
        $itemId = (int)($params['id'] ?? 0);
        $tagId = (int)($params['tagId'] ?? 0);
        if (!Ownership::canDetachTag((int)$user['id'], $itemId, $tagId)) Response::json(['ok' => false, 'error' => 'not_found'], 404);
        Db::delete('item_tags', 'item_id = ? AND tag_id = ?', [$itemId, $tagId]);
        Response::json(['ok' => true]);
    }

    public static function normalizeTag(string $raw): ?string
    {
        $name = strtolower(trim($raw));
        if ($name === '') return null;
        if (mb_strlen($name) > 40) return null;
        if (preg_match(self::NAME_PATTERN, $name) !== 1) return null;
        return $name;
    }
}
