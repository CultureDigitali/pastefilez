<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\ShareService;

final class ShareController extends Controller
{
    public function create(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $pwd = Request::input('password');
        $pwd = is_string($pwd) ? trim($pwd) : '';
        $pwd = $pwd === '' ? null : $pwd;
        $exp = trim(Request::inputString('expires_at', ''));
        $expTs = null;
        if ($exp !== '') {
            $parsed = strtotime($exp);
            if ($parsed === false) Response::json(['ok' => false, 'error' => 'invalid_expires_at'], 400);
            $expTs = $parsed;
        }
        $mv = Request::input('max_views');
        $mv = is_numeric($mv) ? (int)$mv : null;
        $r = ShareService::create($id, (int)$user['id'], $pwd, $expTs, $mv);
        if (!$r['ok']) Response::json($r, 400);
        Audit::log('item.share', 'item:' . $id, ['expires_at' => $expTs, 'has_password' => $pwd !== null]);
        Response::json(['ok' => true, 'url' => $r['url']]);
    }

    public function delete(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        if (!ShareService::revoke($id, (int)$user['id'])) Response::json(['ok' => false, 'error' => 'not_found'], 404);
        Audit::log('item.unshare', 'item:' . $id);
        Response::json(['ok' => true]);
    }
}
