<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\Request;

final class ApiAuth
{
    public static function user(bool $allowApiToken = false): ?array
    {
        $jwt = Request::bearerToken();
        if ($jwt === null || $jwt === '') {
            return $allowApiToken ? self::userFromApiToken() : null;
        }
        $payload = JwtService::decode($jwt);
        if (!is_array($payload) || !isset($payload['sub'])) return null;
        return self::activeUser((int)$payload['sub']);
    }

    public static function userFromApiToken(): ?array
    {
        $token = Request::inputString('api_token');
        if ($token === '') return null;
        $row = Db::first(
            'SELECT * FROM api_tokens WHERE token_hash = ? AND (expires_at IS NULL OR expires_at > ?)',
            [Crypto::hmac($token), time()]
        );
        if (!$row) return null;
        $u = self::activeUser((int)$row['user_id']);
        if ($u !== null) {
            Db::update('api_tokens', ['last_used_at' => time()], 'id = ?', [(int)$row['id']]);
        }
        return $u;
    }

    public static function activeUser(int $userId): ?array
    {
        if ($userId <= 0) return null;
        return Db::first('SELECT * FROM users WHERE id = ? AND is_active = 1', [$userId]);
    }

    public static function require(): array
    {
        $u = self::user();
        if ($u === null) \App\Core\Response::json(['error' => 'unauthenticated'], 401);
        return $u;
    }
}
