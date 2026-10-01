<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Services\StorageService;

final class E2eShareController extends Controller
{
    public function create(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $item = Db::first('SELECT id, user_id, mime, size FROM items WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$id, (int)$user['id']]);
        if (!$item) Response::json(['ok' => false, 'error' => 'not_found'], 404);

        $f = Request::file('payload');
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::json(['ok' => false, 'error' => 'no_payload'], 400);
        }
        $size = (int)($f['size'] ?? 0);
        if ($size <= 0 || $size > 50 * 1024 * 1024) {
            if (is_file($f['tmp_name'])) @unlink($f['tmp_name']);
            Response::json(['ok' => false, 'error' => 'size'], 413);
        }

        $exp = trim(Request::inputString('expires_at', ''));
        $expTs = null;
        if ($exp !== '') {
            $parsed = strtotime($exp);
            if ($parsed === false) {
                if (is_file($f['tmp_name'])) @unlink($f['tmp_name']);
                Response::json(['ok' => false, 'error' => 'invalid_expires_at'], 400);
            }
            $expTs = $parsed;
        }
        $mv = Request::input('max_views');
        $mv = is_numeric($mv) ? (int)$mv : null;

        if (!StorageService::checkQuota((int)$user['id'], $size)) {
            if (is_file($f['tmp_name'])) @unlink($f['tmp_name']);
            Response::json(['ok' => false, 'error' => 'quota_exceeded'], 413);
        }

        $storage = (string)\App\Core\Config::get('storage_path');
        $dir = $storage . '/e2e';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $name = Crypto::randomToken(24);
        $dest = $dir . '/' . $name;
        if (!@move_uploaded_file($f['tmp_name'], $dest)) {
            if (!@copy($f['tmp_name'], $dest)) {
                Response::json(['ok' => false, 'error' => 'write_failed'], 500);
            }
            @unlink($f['tmp_name']);
        }
        @chmod($dest, 0640);

        $token = Crypto::randomToken(28);
        $now = time();
        $eid = Db::insert('e2e_shares', [
            'user_id' => (int)$user['id'],
            'item_id' => $id,
            'token' => $token,
            'payload_path' => 'e2e/' . $name,
            'size' => $size,
            'expires_at' => $expTs,
            'max_views' => $mv,
            'views' => 0,
            'created_at' => $now,
        ]);
        Audit::log('e2e_share.create', 'item:' . $id, ['eid' => $eid, 'size' => $size, 'expires_at' => $expTs]);
        Response::json(['ok' => true, 'url' => url('s-e2e/' . $token)]);
    }

    public function revoke(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $row = Db::first('SELECT * FROM e2e_shares WHERE id = ? AND user_id = ?', [$id, (int)$user['id']]);
        if (!$row) Response::notFound();
        Db::update('e2e_shares', ['revoked_at' => time()], 'id = ?', [$id]);
        $abs = (string)\App\Core\Config::get('storage_path') . '/' . $row['payload_path'];
        if (is_file($abs)) @unlink($abs);
        Audit::log('e2e_share.revoke', 'eid:' . $id);
        Response::json(['ok' => true]);
    }

    public function page(array $params): void
    {
        $token = (string)($params['token'] ?? '');
        $row = Db::first('SELECT s.*, i.original_name AS source_name, i.size AS source_size FROM e2e_shares s JOIN items i ON i.id = s.item_id WHERE s.token = ?', [$token]);
        if (!$row) { $this->view('errors/404', []); return; }
        if ($row['revoked_at']) { $this->view('share/expired', [], 'centered'); return; }
        if ($row['expires_at'] && (int)$row['expires_at'] < time()) { $this->view('share/expired', [], 'centered'); return; }
        if ($row['max_views'] && (int)$row['views'] >= (int)$row['max_views']) { $this->view('share/expired', [], 'centered'); return; }
        $this->view('share/e2e', ['token' => $token, 'size' => (int)$row['size'], 'source' => $row['source_name']], 'centered');
    }

    public function payload(array $params): void
    {
        $token = (string)($params['token'] ?? '');
        $row = Db::first('SELECT id, user_id, payload_path, revoked_at, expires_at, max_views, views FROM e2e_shares WHERE token = ?', [$token]);
        if (!$row) Response::notFound();
        if ($row['revoked_at']) Response::notFound();
        if ($row['expires_at'] && (int)$row['expires_at'] <= time()) Response::notFound();
        $st = Db::run(
            'UPDATE e2e_shares SET views = views + 1
             WHERE id = ? AND (max_views IS NULL OR max_views = 0 OR views < max_views)',
            [$row['id']]
        );
        if ($st->rowCount() !== 1) Response::notFound();
        $abs = (string)\App\Core\Config::get('storage_path') . '/' . $row['payload_path'];
        if (!is_file($abs)) Response::notFound();
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($abs));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Disposition: attachment; filename="encrypted.bin"');
        readfile($abs);
        exit;
    }
}
