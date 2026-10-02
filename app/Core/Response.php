<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function json(mixed $data, int $status = 200, array $headers = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            foreach ($headers as $k => $v) header("$k: $v");
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function html(string $body, int $status = 200, array $headers = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
            foreach ($headers as $k => $v) header("$k: $v");
        }
        echo $body;
        exit;
    }

    public static function redirect(string $path, int $status = 302): void
    {
        $target = preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1
            ? $path
            : url(ltrim($path, '/'));
        if (!headers_sent()) {
            http_response_code($status);
            header('Location: ' . $target);
        }
        exit;
    }

    public static function noContent(): void
    {
        if (!headers_sent()) http_response_code(204);
        exit;
    }

    public static function notFound(): void
    {
        http_response_code(404);
        echo View::render('errors/404', ['app_name' => Config::get('app_name')]);
        exit;
    }

    public static function forbidden(string $msg = 'Forbidden'): void
    {
        http_response_code(403);
        if (Request::isAjax()) self::json(['error' => $msg], 403);
        echo View::render('errors/403', ['app_name' => Config::get('app_name'), 'msg' => $msg]);
        exit;
    }

    public static function stored(string $relPath, bool $encrypted, string $name, string $mime, bool $inline = false): void
    {
        $storage = (string)Config::get('storage_path');
        $abs = $storage . '/' . $relPath;
        if (!is_file($abs)) { self::notFound(); }
        $tmp = null;
        $path = $abs;
        if ($encrypted && \App\Services\StorageService::isEncryptedBlob($relPath)) {
            $tmp = \App\Services\StorageService::decryptToTemp($relPath, true);
            if ($tmp === null) {
                Logger::error('response.decrypt_failed', ['path' => $relPath]);
                self::notFound();
            }
            $path = $tmp;
        }
        if ($tmp !== null) {
            register_shutdown_function(static function () use ($tmp): void {
                if (is_file($tmp)) @unlink($tmp);
            });
        }
        if ($inline) {
            self::stream($path, $mime, false, $name);
        }
        self::file($path, $name, $mime);
    }

    public static function file(string $path, string $name, string $mime): void
    {
        if (!is_file($path)) { self::notFound(); }
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
        }
        readfile($path);
        exit;
    }

    public static function stream(string $path, string $mime, bool $download = false, ?string $name = null): void
    {
        if (!is_file($path)) self::notFound();
        $inlineSafe = \App\Services\UploadGuard::inlineSafe($mime);
        $forceDownload = $download || !$inlineSafe;
        $serveMime = $forceDownload ? 'application/octet-stream' : $mime;
        $csp = strtolower($serveMime) === 'application/pdf'
            ? "default-src 'none'; object-src 'none'"
            : "default-src 'none'; sandbox";
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $serveMime);
            header('Content-Length: ' . filesize($path));
            header('Accept-Ranges: bytes');
            header('X-Content-Type-Options: nosniff');
            header('Content-Security-Policy: ' . $csp);
            header('Cache-Control: private, no-store');
            $disposition = $forceDownload ? 'attachment' : 'inline';
            header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($name ?? basename($path)) . '"');
        }
        $fp = fopen($path, 'rb');
        if ($fp === false) { self::notFound(); }
        fpassthru($fp);
        fclose($fp);
        exit;
    }
}
