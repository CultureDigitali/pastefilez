<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        return Session::csrfToken();
    }

    public static function check(?string $token): bool
    {
        if (!$token) return false;
        $sess = Session::csrfToken();
        return hash_equals($sess, $token);
    }

    public static function validateRequest(): void
    {
        self::guardPostSize();
        $m = Request::method();
        if (!in_array($m, ['POST','PUT','DELETE','PATCH'], true)) return;
        if (self::isApiRequest() || self::isHookRequest()) return;
        if (self::isShareTargetRequest()) return;
        $t = $_POST['_csrf'] ?? Request::header('X-CSRF-Token') ?? null;
        if (!is_string($t)) $t = null;
        if (!self::check($t)) {
            while (ob_get_level() > 0) ob_end_clean();
            if (Request::isAjax()) Response::json(['error' => 'Invalid CSRF token'], 419);
            http_response_code(419);
            echo View::render('errors/csrf', ['app_name' => Config::get('app_name')]);
            exit;
        }
    }

    private static function guardPostSize(): void
    {
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len <= 0) return;
        $max = (int)ini_get('post_max_size');
        if ($max <= 0) return;
        if (preg_match('/^(\d+)([KMG])?B?$/i', trim((string)ini_get('post_max_size')), $m)) {
            $n = (int)$m[1];
            $mult = strtoupper($m[2] ?? '') === 'K' ? 1024 : (strtoupper($m[2] ?? '') === 'M' ? 1048576 : (strtoupper($m[2] ?? '') === 'G' ? 1073741824 : 1));
            $max = $n * $mult;
        }
        if ($len > $max) {
            while (ob_get_level() > 0) ob_end_clean();
            http_response_code(413);
            echo View::render('errors/csrf', ['app_name' => Config::get('app_name')]);
            exit;
        }
    }

private static function isShareTargetRequest(): bool
    {
        if (Request::path() !== '/upload') return false;
        if (empty($_FILES['file'])) return false;
        if (!empty($_POST['_csrf'])) return false;
        if (!empty(Request::header('X-CSRF-Token'))) return false;
        return strtolower((string)Request::header('Sec-Fetch-Site')) === 'null';
    }

    private static function isApiRequest(): bool
    {
        return str_starts_with(Request::path(), '/api/');
    }

    private static function isHookRequest(): bool
    {
        return str_starts_with(Request::path(), '/hooks/');
    }
}
