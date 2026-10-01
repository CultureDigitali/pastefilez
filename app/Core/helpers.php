<?php
declare(strict_types=1);

function e(mixed $v): string
{
    if ($v === null || is_bool($v)) $v = $v ? '1' : '';
    if (is_array($v) || is_object($v)) $v = '';
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = rtrim(\App\Core\Config::get('app_url') ?: '', '/');
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url(ltrim($path, '/')));
    exit;
}

function csrf_token(): string
{
    return \App\Core\Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function flash_set(string $type, string $msg): void
{
    \App\Core\Session::flash($type, $msg);
}

function flash_get(string $type): ?string
{
    return \App\Core\Session::flash($type);
}

function t(string $key, array $vars = []): string
{
    return \App\Core\I18n::t($key, $vars);
}

function bytes_to_human(int $b): string
{
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
    return round($b, 2) . ' ' . $u[$i];
}

function random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}
