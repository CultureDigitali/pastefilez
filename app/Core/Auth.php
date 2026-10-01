<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Role;

final class Auth
{
    public static function isAdminRole(?string $role): bool
    {
        return Role::isAdmin($role);
    }

    public static function isAdminUser(?array $user): bool
    {
        return self::isAdminRole(isset($user['role']) ? (string)$user['role'] : null);
    }

    public static function can(?string $role, string $grant): bool
    {
        $grants = Role::grants((string)$role);
        if (in_array('*', $grants, true)) return true;
        return in_array($grant, $grants, true);
    }

    public static function requireGrant(string $grant): array
    {
        $u = self::require();
        if (!self::can((string)$u['role'], $grant)) Response::forbidden();
        return $u;
    }

    public static function attempt(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $user = Db::first('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$user) {
            RateLimiter::hit('login:' . Request::ip());
            Logger::info('login.fail.no_user', ['email' => $email, 'ip' => Request::ip()]);
            Audit::log('login_fail', 'email:' . $email, ['ip' => Request::ip(), 'why' => 'no_user']);
            return ['ok' => false, 'reason' => 'invalid'];
        }
        if ((int)$user['is_active'] !== 1) {
            Audit::log('login_fail', 'email:' . $email, ['ip' => Request::ip(), 'why' => 'disabled']);
            return ['ok' => false, 'reason' => 'disabled'];
        }
        if ($user['locked_until'] && (int)$user['locked_until'] > time()) {
            $wait = (int)$user['locked_until'] - time();
            return ['ok' => false, 'reason' => 'locked', 'wait' => $wait];
        }
        if (!Crypto::verify($password, $user['password_hash'])) {
            Db::run('UPDATE users SET failed_attempts = failed_attempts + 1, updated_at = ? WHERE id = ?', [time(), $user['id']]);
            $fa = (int)Db::value('SELECT failed_attempts FROM users WHERE id = ?', [$user['id']]);
            $lock = null;
            if ($fa >= 5) {
                $lock = time() + (int)pow(2, min($fa - 5, 6)) * 60;
                Db::update('users', ['locked_until' => $lock], 'id = ?', [$user['id']]);
            }
            RateLimiter::hit('login:' . Request::ip());
            Logger::info('login.fail.bad_password', ['email' => $email, 'ip' => Request::ip(), 'attempts' => $fa]);
            Audit::log('login_fail', 'email:' . $email, ['ip' => Request::ip(), 'why' => 'bad_password', 'attempts' => $fa]);
            if ($lock !== null) {
                return ['ok' => false, 'reason' => 'locked', 'wait' => (int)$lock - time(), 'attempts' => $fa];
            }
            return ['ok' => false, 'reason' => 'invalid', 'attempts' => $fa, 'locked' => null];
        }
        if (Crypto::needsRehash($user['password_hash'])) {
            Db::update('users', [
                'password_hash' => Crypto::hash($password),
                'failed_attempts' => 0,
                'locked_until' => null,
                'updated_at' => time(),
            ], 'id = ?', [$user['id']]);
        } else {
            Db::update('users', [
                'failed_attempts' => 0,
                'locked_until' => null,
                'updated_at' => time(),
            ], 'id = ?', [$user['id']]);
        }
        return ['ok' => true, 'user' => $user, 'requires_totp' => (int)$user['totp_enabled'] === 1];
    }

    public static function login(array $user, bool $requireTotp = false): void
    {
        $previous = self::id();
        $previousSid = session_id();
        Session::regenerate(true);
        Session::set('user_id', (int)$user['id']);
        Session::set('user_role', $user['role']);
        Session::set('user_email', $user['email']);
        Session::set('lang', $user['language'] ?? 'it');
        Session::set('theme', $user['theme'] ?? 'dark');
        Session::set('auth_time', time());
        Session::set('requires_totp', $requireTotp);
        Session::set('totp_verified', !$requireTotp);
        self::registerSession((int)$user['id'], $previous, is_string($previousSid) ? $previousSid : null);
        Db::update('users', [
            'last_login_at' => time(),
            'last_login_ip' => Request::ip(),
            'updated_at' => time(),
        ], 'id = ?', [$user['id']]);
        Audit::log('login', 'user:' . $user['id']);
    }

    private static function registerSession(int $userId, ?int $previousId, ?string $previousSid): void
    {
        $sid = session_id();
        if (!is_string($sid) || $sid === '') return;
        $now = time();
        $ttl = (int)Config::get('session_lifetime_min', 60) * 60;
        if ($previousSid !== null && $previousSid !== '' && $previousSid !== $sid) {
            Db::delete('sessions', 'id = ?', [$previousSid]);
        }
        try {
            Db::run('INSERT INTO sessions(id, user_id, ip, ua, csrf_token, payload, created_at, last_seen_at, expires_at)
                     VALUES(?,?,?,?,?,?,?,?,?)
                     ON CONFLICT(id) DO UPDATE SET user_id = excluded.user_id, ip = excluded.ip, ua = excluded.ua,
                     last_seen_at = excluded.last_seen_at, expires_at = excluded.expires_at',
                [$sid, $userId, Request::ip(), Request::ua(), Session::csrfToken(), null, $now, $now, $now + $ttl]);
        } catch (\Throwable) {
        }
    }

    public static function logout(): void
    {
        $uid = self::id();
        $sid = session_id();
        if ($uid) {
            Audit::log('logout', 'user:' . $uid);
            if (is_string($sid) && $sid !== '') Db::delete('sessions', 'id = ?', [$sid]);
        }
        Session::destroy();
    }

    public static function sessions(int $userId): array
    {
        self::pruneSessions();
        return Db::all('SELECT id, ip, ua, created_at, last_seen_at, expires_at FROM sessions WHERE user_id = ? ORDER BY last_seen_at DESC', [$userId]);
    }

    public static function revokeSession(int $userId, string $sid): bool
    {
        if ($sid === '' || strlen($sid) > 128 || !preg_match('/^[a-zA-Z0-9,\-]+$/', $sid)) return false;
        return Db::delete('sessions', 'id = ? AND user_id = ?', [$sid, $userId]) > 0;
    }

    public static function revokeAllSessions(int $userId, ?string $keep = null): int
    {
        if ($keep !== null && $keep !== '') {
            return Db::delete('sessions', 'user_id = ? AND id <> ?', [$userId, $keep]);
        }
        return Db::delete('sessions', 'user_id = ?', [$userId]);
    }

    public static function pruneSessions(): int
    {
        return Db::delete('sessions', 'expires_at < ?', [time() - 86400]);
    }

    private static function isSessionValid(int $userId): bool
    {
        $sid = session_id();
        if (!is_string($sid) || $sid === '') return true;
        $row = Db::first('SELECT id, expires_at FROM sessions WHERE id = ? AND user_id = ?', [$sid, $userId]);
        if ($row === null) return false;
        if ((int)$row['expires_at'] <= time()) {
            Db::delete('sessions', 'id = ?', [$sid]);
            return false;
        }
        $ttl = (int)Config::get('session_lifetime_min', 60) * 60;
        Db::update('sessions', [
            'last_seen_at' => time(),
            'expires_at' => time() + $ttl,
        ], 'id = ?', [$sid]);
        return true;
    }

    public static function id(): ?int
    {
        $id = Session::get('user_id');
        return $id ? (int)$id : null;
    }

    public static function check(): bool
    {
        return self::id() !== null && Session::get('totp_verified') === true;
    }

    public static function user(): ?array
    {
        $id = self::id();
        if (!$id) return null;
        return Db::first('SELECT * FROM users WHERE id = ? AND is_active = 1', [$id]);
    }

    public static function isAdmin(): bool
    {
        return self::check() && self::isAdminRole((string)Session::get('user_role', ''));
    }

    public static function require(): array
    {
        if (!self::check()) {
            if (Request::isAjax()) Response::json(['error' => 'unauthenticated'], 401);
            Response::redirect('/login');
        }
        $u = self::user();
        if (!$u) { self::logout(); Response::redirect('/login'); }
        if (!self::isSessionValid((int)$u['id'])) { self::logout(); Response::redirect('/login'); }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::require();
        if (!self::isAdminUser($u)) Response::forbidden();
        return $u;
    }

    public static function verifyTotp(array $user, string $code): bool
    {
        if (!(int)$user['totp_enabled']) return true;
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $secret = Crypto::decryptFromBase64((string)$user['totp_secret']);
        if (!$secret) return false;
        return TOTP::verify($secret, $code, 1);
    }
}
