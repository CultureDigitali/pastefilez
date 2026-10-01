<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;

final class FolderController extends Controller
{
    public function create(): void
    {
        $user = Auth::require();
        $name = trim(Request::inputString('name'));
        $parent = Request::input('parent_id');
        $parentId = is_numeric($parent) ? (int)$parent : null;
        if ($name === '' || mb_strlen($name) > 80) Response::json(['ok' => false, 'error' => 'invalid_name']);
        if ($parentId !== null) {
            $owned = Db::first('SELECT id FROM folders WHERE id = ? AND user_id = ?', [$parentId, $user['id']]);
            if (!$owned) Response::json(['ok' => false, 'error' => 'invalid_parent'], 400);
        }
        $id = Db::insert('folders', [
            'user_id' => $user['id'],
            'parent_id' => $parentId,
            'name' => $name,
            'created_at' => time(),
        ]);
        Audit::log('folder.create', 'folder:' . $id);
        Response::redirect('/items');
    }

    public function delete(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        Db::delete('folders', 'id = ? AND user_id = ?', [$id, $user['id']]);
        Audit::log('folder.delete', 'folder:' . $id);
        Response::json(['ok' => true]);
    }
}
