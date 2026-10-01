<?php
declare(strict_types=1);

namespace App\Core;

final class Bootstrap
{
    public static function init(): void
    {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
        ini_set('html_errors', '0');
        Config::load();
        ini_set('error_log', (string)Config::get('storage_path') . '/logs/php-error.log');
        self::ensureInstalled();
        self::securityHeaders();
        self::configureSession();
        Db::init();
        I18n::init();
        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
    }


    public static function ensureInstalled(): void
    {
        $key = (string)Config::get('master_key_path');
        if (!is_file($key)) {
            Installer::redirectToInstall();
        }
    }

    public static function securityHeaders(): void
    {
        if (headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
            . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net; "
            . "font-src 'self' data:; connect-src 'self'; frame-src 'self'; frame-ancestors 'none'; "
            . "form-action 'self'; base-uri 'self'; object-src 'none'");
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 0) == 443) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function configureSession(): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 0) == 443;
        $secure = (bool)Config::get('cookie_secure') || $https;
        $samesite = (string)Config::get('cookie_samesite', 'Strict');
        if ($https && $samesite === 'None') $samesite = 'Strict';
        session_name((string)Config::get('session_name', 'pz_sess'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $samesite,
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function handleException(\Throwable $ex): void
    {
        Logger::error('Unhandled exception: ' . $ex->getMessage(), [
            'file' => $ex->getFile(),
            'line' => $ex->getLine(),
            'trace' => $ex->getTraceAsString(),
        ]);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        if ((bool)Config::get('app_debug')) {
            echo '<pre>' . htmlspecialchars($ex->getMessage() . "\n\n" . $ex->getTraceAsString(), ENT_QUOTES) . '</pre>';
        } else {
            echo View::render('errors/500', ['app_name' => Config::get('app_name')]);
        }
        exit;
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $severity) === 0) return false;
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        if (($severity & $fatal) !== 0) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }
        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            Logger::debug('php.deprecated', ['msg' => $message, 'file' => basename($file), 'line' => $line]);
            return true;
        }
        Logger::warning('php.diagnostic', [
            'sev' => $severity,
            'msg' => $message,
            'file' => basename($file),
            'line' => $line,
        ]);
        return true;
    }
}
