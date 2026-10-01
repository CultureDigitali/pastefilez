<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Db;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\ShareService;
use App\Services\StorageService;

final class SharePublicController extends Controller
{
    public function page(array $params): void
    {
        $token = (string)($params['token'] ?? '');
        $row = ShareService::get($token);
        if (!$row) { Response::notFound(); }
        if (self::isExpired($row) || self::isViewLimitReached($row)) {
            $this->view('share/expired', [], 'centered');
            return;
        }
        if (!empty($row['share_password_hash']) && empty($_SESSION['share_unlocked_' . $token])) {
            $this->view('share/unlock', ['token' => $token], 'centered');
            return;
        }
        $this->view('share/public', ['item' => $row, 'token' => $token], 'centered');
    }

    public function unlock(array $params): void
    {
        $token = (string)($params['token'] ?? '');
        $row = ShareService::get($token);
        if (!$row) { Response::notFound(); }
        if (self::isExpired($row) || self::isViewLimitReached($row)) {
            $this->view('share/expired', [], 'centered');
            return;
        }
        $pwd = Request::inputString('password');
        $rlKey = 'share_unlock:' . substr(hash('sha256', $token . '|' . Request::ip()), 0, 24);
        if (!RateLimiter::check($rlKey, 10)) {
            Response::json(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        RateLimiter::hit($rlKey, 10);
        if (!password_verify($pwd, (string)$row['share_password_hash'])) {
            Audit::log('share_unlock_fail', 'item:' . $row['id'], ['ip' => Request::ip()]);
            $this->view('share/unlock', ['token' => $token, 'error' => t('share.wrong_password')], 'centered');
            return;
        }
        $_SESSION['share_unlocked_' . $token] = true;
        Audit::log('share.unlock', 'item:' . $row['id']);
        Response::redirect('/s/' . $token);
    }

    public function file(array $params): void
    {
        $token = (string)($params['token'] ?? '');
        $row = ShareService::get($token);
        if (!$row || self::isExpired($row) || self::isViewLimitReached($row)) Response::notFound();
        if (!empty($row['share_password_hash']) && empty($_SESSION['share_unlocked_' . $token])) {
            Response::redirect('/s/' . $token);
        }
        if (!self::consumeView($row)) Response::notFound();
        Response::stored((string)$row['storage_path'], (bool)($row['is_encrypted'] ?? 0), (string)$row['original_name'], (string)$row['mime']);
    }

    public function thumb(array $params): void
    {
        $token = (string)($params['token'] ?? '');
        $row = ShareService::get($token);
        if (!$row || self::isExpired($row)) Response::notFound();
        if (!$row['thumbnail_path']) Response::notFound();
        $path = (string)Config::get('storage_path') . '/' . $row['thumbnail_path'];
        if (!is_file($path)) Response::notFound();
        Response::stream($path, 'image/jpeg', false);
    }

    private static function isExpired(array $row): bool
    {
        return $row['share_expires_at'] && (int)$row['share_expires_at'] < time();
    }

    private static function isViewLimitReached(array $row): bool
    {
        return $row['share_max_views'] && (int)$row['share_view_count'] >= (int)$row['share_max_views'];
    }

    public static function consumeView(array $row): bool
    {
        $st = Db::run(
            'UPDATE items SET share_view_count = share_view_count + 1
             WHERE id = ? AND user_id = ?
               AND (share_max_views IS NULL OR share_max_views = 0 OR share_view_count < share_max_views)',
            [$row['id'], $row['user_id']]
        );
        return $st->rowCount() === 1;
    }
}
