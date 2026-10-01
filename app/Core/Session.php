<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    private const FLASH_TYPES = ['success', 'error', 'warning', 'info'];

    private static array $fresh = [];

    public static function get(string $k, mixed $default = null): mixed
    {
        return $_SESSION[$k] ?? $default;
    }

    public static function set(string $k, mixed $v): void
    {
        if (self::isFlashKey($k)) {
            $_SESSION[$k] = self::sanitize((string)$v);
            self::$fresh[substr($k, 6)] = true;
            return;
        }
        $_SESSION[$k] = $v;
    }

    public static function forget(string $k): void
    {
        if (self::isFlashKey($k)) unset(self::$fresh[substr($k, 6)]);
        unset($_SESSION[$k]);
    }

    public static function flash(string $type, ?string $msg = null): ?string
    {
        $key = 'flash_' . $type;
        if ($msg === null) {
            $isFresh = !empty(self::$fresh[$type]);
            unset(self::$fresh[$type]);
            if ($isFresh) return null;
            if (!isset($_SESSION[$key])) return null;
            $v = $_SESSION[$key];
            unset($_SESSION[$key]);
            return $v;
        }
        self::set($key, $msg);
        return null;
    }

    public static function isFlashKey(string $k): bool
    {
        if (!str_starts_with($k, 'flash_')) return false;
        return in_array(substr($k, 6), self::FLASH_TYPES, true);
    }

    private static function sanitize(string $msg): string
    {
        $msg = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $msg) ?? '';
        $msg = trim($msg);
        if (mb_strlen($msg) > 500) $msg = mb_substr($msg, 0, 500);
        return $msg;
    }

    public static function regenerate(bool $deleteOld = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOld);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }
    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function invalidateUserSessions(int $userId): int
    {
        return \App\Core\Auth::revokeAllSessions($userId);
    }
}
