<?php
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public static function debug(string $msg, array $ctx = []): void { self::log('debug', $msg, $ctx); }
    public static function info(string $msg, array $ctx = []): void { self::log('info', $msg, $ctx); }
    public static function warning(string $msg, array $ctx = []): void { self::log('warning', $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void { self::log('error', $msg, $ctx); }

    public static function log(string $level, string $msg, array $ctx = []): void
    {
        $cur = self::LEVELS[(string)Config::get('log_level', 'info')] ?? 1;
        $lv = self::LEVELS[$level] ?? 1;
        if ($lv < $cur) return;
        $line = sprintf(
            "[%s] %s: %s %s\n",
            date('c'),
            strtoupper($level),
            $msg,
            $ctx ? json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );
        $dir = (string)Config::get('storage_path') . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $path = $dir . '/app.log';
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
