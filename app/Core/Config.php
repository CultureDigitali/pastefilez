<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $data = [];
    private static bool $loaded = false;
    private static ?string $projectRoot = null;
    private static ?array $realEnv = null;

    public static function load(): void
    {
        if (self::$loaded) return;
        self::$projectRoot = self::$projectRoot ?? dirname(__DIR__, 2);
        self::$realEnv = self::snapshotRealEnv();
        $envPath = self::$projectRoot . '/.env';
        if (is_readable($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (!str_contains($line, '=')) continue;
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) continue;
                $value = array_key_exists($k, self::$realEnv)
                    ? (string)self::$realEnv[$k]
                    : self::parseEnvValue($v);
                $_ENV[$k] = $value;
                putenv("$k=$value");
            }
        }
        self::$data = [
            'app_name' => $_ENV['APP_NAME'] ?? 'PasteFilez',
            'app_env' => $_ENV['APP_ENV'] ?? 'production',
            'app_debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'app_url' => rtrim($_ENV['APP_URL'] ?? '', '/'),
            'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC',
            'default_language' => $_ENV['DEFAULT_LANGUAGE'] ?? 'en',
            'db_path' => self::abs($_ENV['DB_PATH'] ?? '../storage/app.sqlite'),
            'storage_path' => self::abs($_ENV['STORAGE_PATH'] ?? '../storage'),
            'session_name' => $_ENV['SESSION_NAME'] ?? 'pz_sess',
            'session_lifetime_min' => (int)($_ENV['SESSION_LIFETIME_MIN'] ?? 60),
            'cookie_secure' => filter_var($_ENV['COOKIE_SECURE'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'cookie_samesite' => $_ENV['COOKIE_SAMESITE'] ?? 'Strict',
            'master_key_path' => self::abs($_ENV['MASTER_KEY_PATH'] ?? '../storage/.key'),
            'encrypt_files' => filter_var($_ENV['ENCRYPT_FILES_AT_REST'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'max_upload_mb' => (int)($_ENV['MAX_UPLOAD_MB'] ?? 10240),
            'quota_per_user_mb' => (int)($_ENV['QUOTA_PER_USER_MB'] ?? 10240),
            'allowed_mime' => $_ENV['ALLOWED_MIME'] ?? 'image/*,video/*,audio/*,application/pdf,application/zip,application/json,text/*',
            'default_lang' => $_ENV['DEFAULT_LANG'] ?? 'it',
            'smtp' => [
                'enabled' => filter_var($_ENV['SMTP_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'host' => $_ENV['SMTP_HOST'] ?? '',
                'port' => (int)($_ENV['SMTP_PORT'] ?? 587),
                'user' => $_ENV['SMTP_USER'] ?? '',
                'pass' => $_ENV['SMTP_PASS'] ?? '',
                'from' => $_ENV['SMTP_FROM'] ?? '',
                'from_name' => $_ENV['SMTP_FROM_NAME'] ?? 'PasteFilez',
                'encryption' => $_ENV['SMTP_ENCRYPTION'] ?? 'tls',
            ],
            'google' => [
                'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
                'redirect' => $_ENV['GOOGLE_REDIRECT'] ?? '',
            ],
            'dropbox' => [
                'client_id' => $_ENV['DROPBOX_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['DROPBOX_CLIENT_SECRET'] ?? '',
                'redirect' => $_ENV['DROPBOX_REDIRECT'] ?? '',
            ],
            'log_level' => $_ENV['LOG_LEVEL'] ?? 'info',
            'rate_login_hour' => (int)($_ENV['RATE_LIMIT_LOGIN_PER_HOUR'] ?? 10),
            'rate_api_read_hour' => (int)($_ENV['RATE_LIMIT_API_READ_PER_HOUR'] ?? 0),
            'rate_api_write_hour' => (int)($_ENV['RATE_LIMIT_API_WRITE_PER_HOUR'] ?? 0),
            'rate_api_ai_hour' => (int)($_ENV['RATE_LIMIT_API_AI_PER_HOUR'] ?? 0),
            'rate_api_auth_hour' => (int)($_ENV['RATE_LIMIT_API_AUTH_PER_HOUR'] ?? 0),
            'rate_api_search_hour' => (int)($_ENV['RATE_LIMIT_API_SEARCH_PER_HOUR'] ?? 0),
            'trusted_proxies' => array_filter(array_map('trim', explode(',', $_ENV['TRUSTED_PROXIES'] ?? ''))),
        ];
        date_default_timezone_set(self::$data['timezone']);
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        $parts = explode('.', $key);
        $v = self::$data;
        foreach ($parts as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) return $default;
            $v = $v[$p];
        }
        return $v;
    }

    private static function abs(string $path): string
    {
        if ($path === '' || $path[0] === '/') return $path;
        $base = self::$projectRoot ?? (self::$projectRoot = dirname(__DIR__, 2));
        $full = $base . '/' . $path;
        $dir = dirname($full);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        return $full;
    }

    private static function snapshotRealEnv(): array
    {
        $out = [];
        $env = getenv();
        if (!is_array($env)) $env = [];
        foreach (array_merge($env, $_ENV) as $k => $v) {
            if (is_string($k) && is_string($v) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function parseEnvValue(string $v): string
    {
        $v = trim($v);
        if (strlen($v) >= 2 && $v[0] === '"' && str_ends_with($v, '"')) {
            return preg_replace('/\\\\(.)/', '$1', substr($v, 1, -1)) ?? substr($v, 1, -1);
        }
        if (strlen($v) >= 2 && $v[0] === "'" && str_ends_with($v, "'")) {
            return substr($v, 1, -1);
        }
        return $v;
    }
}
