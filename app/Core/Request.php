<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public static function method(): string
    {
        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
        if ($m === 'POST' && $override) $m = strtoupper((string)$override);
        return $m;
    }

    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $p = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = rtrim((string)Config::get('app_url', ''), '/');
        if ($base && str_starts_with($p, parse_url($base, PHP_URL_PATH) ?: '')) {
            $p = substr($p, strlen(parse_url($base, PHP_URL_PATH) ?: ''));
        }
        return '/' . ltrim($p, '/');
    }

    public static function query(string $k, mixed $default = null): mixed
    {
        return $_GET[$k] ?? $default;
    }

    public static function queryString(string $k, string $default = ''): string
    {
        $v = self::query($k, $default);
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string)$v;
        return $default;
    }

    public static function timestamp(string $k): ?int
    {
        $raw = trim(self::queryString($k, ''));
        if ($raw === '') return null;
        $ts = strtotime($raw);
        return $ts === false ? null : $ts;
    }

    public static function input(string $k, mixed $default = null): mixed
    {
        if (array_key_exists($k, $_POST)) return $_POST[$k];
        if (isset($_GET[$k])) return $_GET[$k];
        $body = self::jsonBody();
        return $body[$k] ?? $default;
    }

    public static function inputString(string $k, string $default = ''): string
    {
        $v = self::input($k, $default);
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string)$v;
        return $default;
    }

    public static function all(): array
    {
        return array_merge(self::jsonBody(), $_POST, $_GET);
    }

    public static function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public static function jsonBody(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (!str_contains($ct, 'application/json')) return $cache = [];
        $raw = file_get_contents('php://input');
        if (!$raw) return $cache = [];
        $d = json_decode($raw, true);
        return $cache = is_array($d) ? $d : [];
    }

    public static function ip(): string
    {
        $proxies = (array)Config::get('trusted_proxies', []);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($proxies && in_array($ip, $proxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) $ip = $first;
        }
        return $ip;
    }

    public static function ua(): string
    {
        return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    public static function header(string $name): ?string
    {
        $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$k]) ? (string)$_SERVER[$k] : null;
    }

    public static function bearerToken(): ?string
    {
        $h = self::header('Authorization');
        if ($h && preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
        return null;
    }
}
