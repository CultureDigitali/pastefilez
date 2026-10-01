<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Models\Item;
use App\Services\ItemIngest;
use App\Services\JobService;
use App\Services\Ownership;
use App\Services\StorageService;
use App\Services\ThumbnailService;
use App\Services\UploadGuard;

final class ItemController extends Controller
{
    public function index(): void
    {
        $user = Auth::require();
        $type = Request::query('type');
        $page = min(max(1, (int)Request::query('page', 1)), 100000);
        $items = Item::list((int)$user['id'], 60, ($page - 1) * 60, null, $type);
        $this->view('items/index', ['items' => $items, 'type' => $type, 'page' => $page]);
    }

    public function show(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $item = Item::find($id, (int)$user['id']);
        if (!$item) Response::notFound();
        $tags = Ownership::itemTags($id, (int)$user['id']);
        $this->view('items/show', ['item' => $item, 'tags' => $tags]);
    }

    public function update(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $item = Item::find($id, (int)$user['id']);
        if (!$item) Response::notFound();
        $name = UploadGuard::safeName(trim(Request::inputString('original_name', $item['original_name'])));
        $folder = Request::input('folder_id');
        $folderId = null;
        if ($folder !== '' && $folder !== null) {
            if (!Ownership::ownsFolder((int)$user['id'], $folder)) Response::json(['ok' => false, 'error' => 'folder_not_found'], 404);
            $folderId = (int)$folder;
        }
        Db::update('items', [
            'original_name' => $name,
            'folder_id' => $folderId,
            'updated_at' => time(),
        ], 'id = ? AND user_id = ?', [$id, (int)$user['id']]);
        Audit::log('item.update', 'item:' . $id);
        Response::redirect('/items/' . $id);
    }

    public function delete(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $item = Item::find($id, (int)$user['id']);
        if (!$item) Response::notFound();
        Db::update('items', ['deleted_at' => time()], 'id = ?', [$id]);
        Audit::log('item.trash', 'item:' . $id);
        Response::json(['ok' => true, 'trashed' => true]);
    }

    public function trash(): void
    {
        $user = Auth::require();
        $rows = Db::all('SELECT i.*, (SELECT COUNT(*) FROM items i2 WHERE i2.user_id = i.user_id AND i2.sha256 = i.sha256 AND i2.deleted_at IS NULL) AS live_refs
            FROM items i WHERE i.user_id = ? AND i.deleted_at IS NOT NULL ORDER BY i.deleted_at DESC LIMIT 200', [(int)$user['id']]);
        $this->view('items/trash', ['items' => $rows]);
    }

    public function restore(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $item = Db::first('SELECT id, user_id FROM items WHERE id = ? AND user_id = ? AND deleted_at IS NOT NULL', [$id, (int)$user['id']]);
        if (!$item) Response::notFound();
        Db::update('items', ['deleted_at' => null, 'updated_at' => time()], 'id = ?', [$id]);
        Audit::log('item.restore', 'item:' . $id);
        Response::json(['ok' => true]);
    }

    public function purge(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $item = Db::first('SELECT * FROM items WHERE id = ? AND user_id = ? AND deleted_at IS NOT NULL', [$id, (int)$user['id']]);
        if (!$item) Response::notFound();
        self::purgeItemFiles($item);
        Db::delete('items', 'id = ?', [$id]);
        Audit::log('item.purge', 'item:' . $id);
        Response::json(['ok' => true]);
    }

    public function emptyTrash(): void
    {
        $user = Auth::require();
        $rows = Db::all('SELECT * FROM items WHERE user_id = ? AND deleted_at IS NOT NULL', [(int)$user['id']]);
        $count = 0;
        foreach ($rows as $r) {
            self::purgeItemFiles($r);
            Db::delete('items', 'id = ?', [(int)$r['id']]);
            $count++;
        }
        Audit::log('trash.empty', 'user:' . $user['id'], ['count' => $count]);
        Response::json(['ok' => true, 'purged' => $count]);
    }

    private static function purgeItemFiles(array $item): void
    {
        $sha = $item['sha256'] ?? null;
        $refs = 0;
        if ($sha) {
            $refs = (int)Db::value('SELECT COUNT(*) FROM items WHERE sha256 = ? AND deleted_at IS NULL AND id != ?', [$sha, (int)$item['id']]);
        }
        if ($refs === 0) {
            if (($item['storage_backend'] ?? 'local') === 'local') {
                StorageService::delete($item['storage_path']);
            }
            if (!empty($item['thumbnail_path'])) {
                @unlink(((string)Config::get('storage_path')) . '/' . $item['thumbnail_path']);
            }
        }
        $e2eRows = Db::all('SELECT payload_path FROM e2e_shares WHERE item_id = ?', [(int)$item['id']]);
        foreach ($e2eRows as $er) {
            $p = ((string)Config::get('storage_path')) . '/' . $er['payload_path'];
            if (is_file($p)) @unlink($p);
        }
        Db::delete('e2e_shares', 'item_id = ?', [(int)$item['id']]);
    }

    public function bulk(): void
    {
        $user = Auth::require();
        $ids = Request::input('ids');
        $action = Request::inputString('action', '');
        if (!is_array($ids) || empty($ids)) Response::json(['ok' => false, 'error' => 'no_items'], 400);
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if (empty($ids)) Response::json(['ok' => false, 'error' => 'no_items'], 400);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $owned = Db::all("SELECT id FROM items WHERE user_id = ? AND id IN ($placeholders) AND deleted_at IS NULL", array_merge([(int)$user['id']], $ids));
        $ownedIds = array_map(fn($r) => (int)$r['id'], $owned);
        if (empty($ownedIds)) Response::json(['ok' => false, 'error' => 'no_owned'], 400);
        $count = 0;
        switch ($action) {
            case 'delete':
                $now = time();
                $ph2 = implode(',', array_fill(0, count($ownedIds), '?'));
                Db::run("UPDATE items SET deleted_at = ? WHERE id IN ($ph2)", array_merge([$now], $ownedIds));
                $count = count($ownedIds);
                Audit::log('item.bulk_delete', 'user:' . $user['id'], ['count' => $count]);
                break;
            case 'tag':
                $tagId = (int)Request::input('tag_id', 0);
                if (!$tagId) Response::json(['ok' => false, 'error' => 'no_tag'], 400);
                $tag = Db::first('SELECT id FROM tags WHERE id = ? AND user_id = ?', [$tagId, (int)$user['id']]);
                if (!$tag) Response::json(['ok' => false, 'error' => 'tag_not_found'], 404);
                foreach ($ownedIds as $iid) {
                    Db::run('INSERT OR IGNORE INTO item_tags(item_id, tag_id) VALUES(?,?)', [$iid, $tagId]);
                    $count++;
                }
                Audit::log('item.bulk_tag', 'user:' . $user['id'], ['count' => $count, 'tag_id' => $tagId]);
                break;
            case 'reanalyze':
                foreach ($ownedIds as $iid) {
                    Db::update('items', ['ai_status' => 'pending', 'ai_error' => null], 'id = ?', [$iid]);
                    JobService::enqueue('analyze', (int)$user['id'], ['item_id' => $iid], 0);
                    $count++;
                }
                Audit::log('item.bulk_reanalyze', 'user:' . $user['id'], ['count' => $count]);
                break;
            default:
                Response::json(['ok' => false, 'error' => 'unknown_action'], 400);
        }
        Response::json(['ok' => true, 'action' => $action, 'count' => $count]);
    }

    public function reanalyze(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $item = Item::find($id, (int)$user['id']);
        if (!$item) Response::notFound();
        Db::update('items', ['ai_status' => 'pending', 'ai_error' => null, 'updated_at' => time()], 'id = ?', [$id]);
        JobService::enqueue('analyze', (int)$user['id'], ['item_id' => $id]);
        Response::json(['ok' => true]);
    }

    public function download(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $item = Item::find($id, (int)$user['id']);
        if (!$item) Response::notFound();
        Response::stored((string)$item['storage_path'], (bool)$item['is_encrypted'], (string)$item['original_name'], (string)$item['mime']);
    }

    public function stream(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $item = Item::find($id, (int)$user['id']);
        if (!$item) Response::notFound();
        Response::stored((string)$item['storage_path'], (bool)$item['is_encrypted'], (string)$item['original_name'], (string)$item['mime'], true);
    }

    public function thumb(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $item = Item::find($id, (int)$user['id']);
        if (!$item || !$item['thumbnail_path']) Response::notFound();
        Response::stored((string)$item['thumbnail_path'], false, 'thumb.jpg', 'image/jpeg', true);
    }

    public function edit(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $item = Item::find($id, (int)$user['id']);
        if (!$item) Response::notFound();
        $path = (string)Config::get('storage_path') . '/' . $item['storage_path'];
        if (!is_file($path)) Response::notFound();
        $this->view('items/edit', ['item' => $item, 'src' => url('/items/' . $id . '/stream')]);
    }

    public function editSave(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $item = Item::find($id, (int)$user['id']);
        if (!$item) Response::json(['ok' => false, 'error' => 'not_found'], 404);
        if (!str_starts_with((string)$item['mime'], 'image/')) {
            Response::json(['ok' => false, 'error' => 'not_an_image'], 400);
        }

        $pngData = Request::inputString('image_data');
        if (str_contains($pngData, '%2B')) $pngData = str_replace('%2B', '+', $pngData);
        if ($pngData === '' || !str_starts_with($pngData, 'data:image/png;base64,')) {
            Response::json(['ok' => false, 'error' => 'invalid_image'], 400);
        }
        $b64 = substr($pngData, 22);
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) < 50) {
            Response::json(['ok' => false, 'error' => 'decode_fail'], 400);
        }
        if (substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            Response::json(['ok' => false, 'error' => 'not_a_png'], 400);
        }
        $info = @getimagesizefromstring($bin);
        if (!is_array($info) || (int)$info[0] < 1 || (int)$info[1] < 1) {
            Response::json(['ok' => false, 'error' => 'unreadable_png'], 400);
        }
        if ((int)$info[0] > 20000 || (int)$info[1] > 20000) {
            Response::json(['ok' => false, 'error' => 'dimensions_too_large'], 413);
        }

        $base = pathinfo((string)$item['original_name'], PATHINFO_FILENAME);
        $base = preg_replace('/_edited$/', '', $base) ?? $base;
        $newName = UploadGuard::safeName(($base !== '' ? $base : 'image') . '_edited.png');

        $tmp = tempnam(sys_get_temp_dir(), 'pzedit');
        if ($tmp === false) Response::json(['ok' => false, 'error' => 'temp_failed'], 500);
        file_put_contents($tmp, $bin);

        $r = ItemIngest::fromUpload($user, [
            'name' => $newName,
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($bin),
        ], '', true);
        if (empty($r['ok'])) {
            @unlink($tmp);
            Response::json($r, (int)($r['status'] ?? 400));
        }
        Audit::log('item.edited', 'item:' . $id, ['new_id' => $r['id']]);
        $this->jsonOk([
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'width' => (int)$info[0],
            'height' => (int)$info[1],
        ] + (isset($r['duplicate_of']) ? ['duplicate_of' => (int)$r['duplicate_of']] : []));
    }
}
