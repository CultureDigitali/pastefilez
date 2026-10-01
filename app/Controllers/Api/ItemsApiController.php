<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Services\ApiAuth;
use App\Services\ItemIngest;

final class ItemsApiController
{
    public function index(): void
    {
        $u = ApiAuth::user(); if (!$u) Response::json(['error' => 'unauthenticated'], 401);
        $rows = Db::all('SELECT id, original_name, mime, size, created_at FROM items WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 100', [$u['id']]);
        Response::json(['items' => $rows]);
    }

    public function show(array $params): void
    {
        $u = ApiAuth::user(); if (!$u) Response::json(['error' => 'unauthenticated'], 401);
        $id = (int)($params['id'] ?? 0);
        $row = Db::first('SELECT * FROM items WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$id, $u['id']]);
        if (!$row) Response::json(['error' => 'not_found'], 404);
        Response::json($row);
    }

    public function create(): void
    {
        $u = ApiAuth::user(); if (!$u) Response::json(['error' => 'unauthenticated'], 401);
        $f = Request::file('file');
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) Response::json(['error' => 'no_file'], 400);
        $r = ItemIngest::fromUpload($u, $f, 'api-', true);
        if (empty($r['ok'])) Response::json($r, (int)($r['status'] ?? 400));
        Response::json(['ok' => true, 'id' => $r['id'], 'name' => $r['name']] + (isset($r['duplicate_of']) ? ['duplicate_of' => $r['duplicate_of']] : []));
    }

    public function delete(array $params): void
    {
        $u = ApiAuth::user(); if (!$u) Response::json(['error' => 'unauthenticated'], 401);
        $id = (int)($params['id'] ?? 0);
        $row = Db::first('SELECT * FROM items WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$id, $u['id']]);
        if (!$row) Response::json(['error' => 'not_found'], 404);
        Db::update('items', ['deleted_at' => time(), 'updated_at' => time()], 'id = ? AND user_id = ?', [$id, $u['id']]);
        Db::update('items', [
            'share_token' => null,
            'share_password_hash' => null,
            'share_expires_at' => null,
        ], 'id = ? AND user_id = ?', [$id, $u['id']]);
        Db::delete('e2e_shares', 'item_id = ? AND user_id = ?', [$id, $u['id']]);
        Response::json(['ok' => true]);
    }

}
