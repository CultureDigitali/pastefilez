<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Request;
use App\Core\Response;
use App\Services\ApiAuth;
use App\Services\ItemIngest;

final class ClipboardApiController
{
    public function store(): void
    {
        $u = ApiAuth::user(true); if (!$u) Response::json(['error' => 'unauthenticated'], 401);
        $f = Request::file('file');
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) Response::json(['error' => 'no_file'], 400);

        $name = Request::inputString('original_name');
        if ($name !== '') $f['name'] = $name;
        elseif (!isset($f['name']) || !is_string($f['name'])) $f['name'] = 'clipboard';

        $r = ItemIngest::fromUpload($u, $f, '', false);
        if (empty($r['ok'])) Response::json($r, (int)($r['status'] ?? 400));

        Audit::log('clipboard.store', 'item:' . $r['id']);
        Response::json(['ok' => true, 'id' => $r['id'], 'name' => $r['name']]);
    }
}
