<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\JwtService;

final class AuthApiController
{
    public function login(): void
    {
        $email = Request::inputString('email');
        $pwd = Request::inputString('password');
        if (!RateLimiter::check('api_login_ip:' . Request::ip(), 120)) Response::json(['error' => 'rate_limited', 'scope' => 'ip'], 429);
        $emailKey = strtolower(trim($email));
        if ($emailKey !== '' && !RateLimiter::check('api_login_email:' . hash('sha256', $emailKey), 10)) {
            Response::json(['error' => 'rate_limited', 'scope' => 'account'], 429);
        }
        RateLimiter::hit('api_login_ip:' . Request::ip(), 120);
        if ($emailKey !== '') RateLimiter::hit('api_login_email:' . hash('sha256', $emailKey), 10);
        $r = Auth::attempt($email, $pwd);
        if (!$r['ok']) {
            if ($r['reason'] === 'locked') Response::json(['error' => $r['reason']], 429);
            Response::json(['error' => $r['reason']], 401);
        }
        if ($r['requires_totp']) Response::json(['error' => 'totp_required'], 401);
        $u = $r['user'];
        $access = JwtService::encode(['sub' => (int)$u['id'], 'email' => $u['email']], 900);
        $refresh = Crypto::randomToken(48);
        $family = bin2hex(random_bytes(16));
        Db::insert('api_refresh_tokens', [
            'user_id' => (int)$u['id'],
            'token_hash' => Crypto::hmac($refresh),
            'family_id' => $family,
            'expires_at' => time() + 86400 * 30,
            'created_at' => time(),
        ]);
        Response::json(['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => 900]);
    }

    public function refresh(): void
    {
        $r = Request::inputString('refresh_token');
        if ($r === '') Response::json(['error' => 'invalid'], 401);
        $hash = Crypto::hmac($r);
        $tokenKey = 'api_refresh:' . substr(hash('sha256', $r), 0, 32);
        if (!RateLimiter::check($tokenKey, 120)) Response::json(['error' => 'rate_limited', 'scope' => 'token'], 429);
        RateLimiter::hit($tokenKey, 120);
        $row = Db::first('SELECT * FROM api_refresh_tokens WHERE token_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?)', [$hash, time()]);
        if (!$row) Response::json(['error' => 'invalid'], 401);
        if ($row['used_at'] !== null) {
            Db::run('UPDATE api_refresh_tokens SET revoked_at = ? WHERE family_id = ?', [time(), $row['family_id']]);
            Response::json(['error' => 'token_reused'], 401);
        }
        Db::run('UPDATE api_refresh_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [time(), $row['id']]);
        $u = Db::first('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int)$row['user_id']]);
        if (!$u) Response::json(['error' => 'invalid'], 401);
        $access = JwtService::encode(['sub' => (int)$u['id'], 'email' => $u['email']], 900);
        $next = Crypto::randomToken(48);
        Db::insert('api_refresh_tokens', [
            'user_id' => (int)$u['id'],
            'token_hash' => Crypto::hmac($next),
            'family_id' => $row['family_id'],
            'expires_at' => time() + 86400 * 30,
            'created_at' => time(),
        ]);
        Response::json(['access_token' => $access, 'refresh_token' => $next, 'expires_in' => 900]);
    }
}
