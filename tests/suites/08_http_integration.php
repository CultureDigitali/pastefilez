<?php
declare(strict_types=1);

use App\Core\Db;
use Tests\T;

T::group('http/integration');

$root = dirname(__DIR__, 2);
$sandbox = sys_get_temp_dir() . '/pf_http_' . getmypid();
$port = 0;
for ($attempt = 0; $attempt < 40; $attempt++) {
    $candidate = 8911 + ((getmypid() + $attempt * 7) % 900);
    $probe = @fsockopen('127.0.0.1', $candidate, $e, $es, 0.15);
    if ($probe === false) { $port = $candidate; break; }
    fclose($probe);
}
if ($port === 0) { $port = 8911 + (getmypid() % 200); }
$base = "http://127.0.0.1:$port";

function rcopy(string $src, string $dst): void
{
    if (is_file($src)) { @copy($src, $dst); return; }
    if (!is_dir($src)) return;
    @mkdir($dst, 0750, true);
    foreach (scandir($src) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        rcopy($src . '/' . $e, $dst . '/' . $e);
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}

rcopy($root, $sandbox);
foreach (['storage/app.sqlite', 'storage/app.sqlite-wal', 'storage/app.sqlite-shm', 'storage/.key'] as $f) @unlink($sandbox . '/' . $f);
foreach (['files', 'thumbs', 'e2e', 'logs', 'cache'] as $d) {
    rrmdir($sandbox . '/storage/' . $d);
    @mkdir($sandbox . '/storage/' . $d, 0750, true);
}
@unlink($sandbox . '/tests/run.php');

$env = (string)file_get_contents($root . '/.env');
$env = preg_replace('/^DB_PATH=.*$/m', 'DB_PATH=' . $sandbox . '/storage/app.sqlite', $env) ?? $env;
$env = preg_replace('/^STORAGE_PATH=.*$/m', 'STORAGE_PATH=' . $sandbox . '/storage', $env) ?? $env;
$env = preg_replace('/^MASTER_KEY_PATH=.*$/m', 'MASTER_KEY_PATH=' . $sandbox . '/storage/.key', $env) ?? $env;
$env = preg_replace('/^APP_URL=.*$/m', 'APP_URL=', $env) ?? $env;
$env = preg_replace('/^COOKIE_SECURE=.*$/m', 'COOKIE_SECURE=false', $env) ?? $env;
file_put_contents($sandbox . '/.env', $env);

$descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $sandbox . '/server.log', 'a'], 2 => ['file', $sandbox . '/server.log', 'a']];
$childEnv = [
    'PATH' => (string)(getenv('PATH') ?: '/usr/bin:/bin'),
    'HOME' => (string)(getenv('HOME') ?: '/tmp'),
    'TMPDIR' => sys_get_temp_dir(),
    'LANG' => 'en_US.UTF-8',
];
$proc = @proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $sandbox . '/public'], $descriptors, $pipes, $sandbox, $childEnv);
if (!is_resource($proc)) {
    T::ok(false, 'dev server starts: ' . (string)@file_get_contents($sandbox . '/server.log'));
    return;
}

$ready = false;
for ($i = 0; $i < 60; $i++) {
    usleep(150000);
    $c = @fsockopen('127.0.0.1', $port, $e, $es, 0.2);
    if ($c) { fclose($c); $ready = true; break; }
}
T::ok($ready, 'dev server is listening on ' . $port);
if (!$ready) { proc_terminate($proc); return; }

$cookie = $sandbox . '/cookies.txt';
@unlink($cookie);

function req(string $base, string $method, string $path, array $opts = []): array
{
    global $cookie;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => (bool)($opts['follow'] ?? false),
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 20,
    ]);
    if (isset($opts['post'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['post']);
    if (isset($opts['headers'])) curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hlen);
    $body = substr($raw, $hlen);
    $hdrs = [];
    foreach (explode("\r\n", $head) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $hdrs[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $hdrs, 'body' => $body, 'raw' => $raw];
}

function csrfOf(string $html): string
{
    if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) return $m[1];
    return '';
}

$r = req($base, 'GET', '/install');
$csrf = csrfOf($r['body']);
T::ok($csrf !== '', 'install page exposes a CSRF token');
if ($csrf === '') { proc_terminate($proc); return; }

$r = req($base, 'POST', '/install', ['post' => ['_csrf' => $csrf, 'email' => 'admin@it.test', 'password' => 'Str0ngPassw0rd!23', 'display_name' => 'Admin']]);
T::ok(in_array($r['status'], [200, 302], true), 'install submits (status ' . $r['status'] . ')');
T::ok(is_file($sandbox . '/storage/.key'), 'install creates the master key');
T::ok(is_file($sandbox . '/storage/app.sqlite'), 'install creates the database');

@unlink($cookie);

$r = req($base, 'GET', '/login');
T::eq(200, $r['status'], '/login returns 200 for an anonymous visitor');
T::eq(1, substr_count($r['body'], '<!doctype'), '/login has exactly one doctype');

$r = req($base, 'GET', '/forgot');
T::eq(1, substr_count($r['body'], '<!doctype'), '/forgot has exactly one doctype');

$csrf = csrfOf($r['body']);
T::ok($csrf !== '', 'login form exposes CSRF token');

$r = req($base, 'POST', '/login', ['post' => ['_csrf' => $csrf, 'email' => 'admin@it.test', 'password' => 'totally-wrong-password']]);
T::contains($r['body'], 'border-rose-500/30', 'bad login renders the inline error message');

$csrf = csrfOf($r['body']);
$r = req($base, 'POST', '/login', ['post' => ['_csrf' => $csrf, 'email' => 'admin@it.test', 'password' => 'Str0ngPassw0rd!23']]);
T::ok(in_array($r['status'], [200, 302], true), 'valid login redirects (status ' . $r['status'] . ')');

$r = req($base, 'GET', '/');
T::eq(200, $r['status'], 'dashboard reachable after login');
T::eq(1, substr_count($r['body'], '<!doctype'), 'dashboard has exactly one doctype');

$csrf = csrfOf($r['body']);
if ($csrf === '') {
    $r = req($base, 'GET', '/upload');
    $csrf = csrfOf($r['body']);
}
T::ok($csrf !== '', 'authenticated page exposes CSRF token');

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$pngPath = $sandbox . '/pixel.png';
file_put_contents($pngPath, $png);

$r = req($base, 'POST', '/upload', ['post' => ['_csrf' => $csrf, 'file' => new CURLFile($pngPath, 'image/png', 'pixel.png')]]);
$json = json_decode($r['body'], true);
T::ok(is_array($json) && ($json['ok'] ?? false) === true, 'png upload accepted: ' . substr($r['body'], 0, 120));
$itemId = (int)($json['id'] ?? 0);

$htmlPath = $sandbox . '/evil.html';
file_put_contents($htmlPath, '<html><script>alert(1)</script></html>');
$r = req($base, 'POST', '/upload', ['post' => ['_csrf' => $csrf, 'file' => new CURLFile($htmlPath, 'text/html', 'evil.html')]]);
T::eq(415, $r['status'], 'text/html upload rejected with 415');

$svgPath = $sandbox . '/evil.svg';
file_put_contents($svgPath, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
$r = req($base, 'POST', '/upload', ['post' => ['_csrf' => $csrf, 'file' => new CURLFile($svgPath, 'image/svg+xml', 'evil.svg')]]);
T::eq(415, $r['status'], 'image/svg+xml upload rejected with 415');

if ($itemId > 0) {
    $r = req($base, 'GET', "/items/$itemId/stream");
    T::eq(200, $r['status'], 'stream returns 200');
    T::eq($png, $r['body'], 'stream body is the exact file bytes');
    T::eq('inline', explode(';', (string)($r['headers']['content-disposition'] ?? ''))[0], 'png streamed inline');
    T::eq('image/png', $r['headers']['content-type'] ?? '', 'png keeps its content type');
    T::eq('nosniff', $r['headers']['x-content-type-options'] ?? '', 'nosniff present');
    T::ok(str_contains((string)($r['headers']['content-security-policy'] ?? ''), 'sandbox'), 'stream carries a sandbox CSP');

    $r = req($base, 'GET', "/items/$itemId/download");
    T::eq('attachment', explode(';', (string)($r['headers']['content-disposition'] ?? ''))[0], 'download forces attachment');
    T::eq('nosniff', $r['headers']['x-content-type-options'] ?? '', 'download carries nosniff');
}

$r = req($base, 'GET', '/items/999999/stream');
T::ok(in_array($r['status'], [404, 302], true), 'unknown item is not served (status ' . $r['status'] . ')');

$r = req($base, 'POST', '/settings/smtp', ['post' => ['_csrf' => $csrf, 'host' => 'x', 'port' => '25', 'from_name' => "y\nAPP_DEBUG=true"]]);
T::ok(in_array($r['status'], [200, 302], true), 'the installer account owns config.write and may save global config (status ' . $r['status'] . ')');
$envAfter = (string)file_get_contents($sandbox . '/.env');
$keyLines = 0;
$debugVals = [];
foreach (explode("\n", $envAfter) as $line) {
    $line = trim($line);
    if (!str_contains($line, '=')) continue;
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    if ($k === 'APP_DEBUG') { $keyLines++; $debugVals[] = $v; }
}
T::eq(1, $keyLines, 'exactly one APP_DEBUG key exists after the injection attempt');
T::eq('false', $debugVals[0] ?? '', 'APP_DEBUG value unchanged by injection');
T::ok(preg_match('/^SMTP_FROM_NAME="?y APP_DEBUG=true"?$/m', $envAfter) === 1, 'the newline payload lands as inert SMTP_FROM_NAME text, never as a new key');

$r = req($base, 'POST', '/settings/webhooks/test', ['post' => ['_csrf' => $csrf, 'target_url' => 'http://169.254.169.254/latest/meta-data/']]);
T::eq(400, $r['status'], 'webhook test blocks link-local metadata URL');
T::notContains($r['body'], 'ComputeCredits', 'no metadata content leaked');

$r = req($base, 'POST', '/settings/webhooks/test', ['post' => ['_csrf' => $csrf, 'target_url' => 'file:///etc/passwd']]);
T::eq(400, $r['status'], 'webhook test blocks file:// scheme');

$r = req($base, 'POST', '/upload/url', ['post' => ['_csrf' => $csrf, 'url' => 'http://127.0.0.1:1/x']]);
T::eq(400, $r['status'], 'upload/url blocks loopback target');

$r = req($base, 'GET', '/api/v1/items');
T::eq(401, $r['status'], 'API rejects unauthenticated requests');

$r = req($base, 'POST', '/login', ['post' => ['email' => 'a@b.test', 'password' => 'x']]);
T::eq(419, $r['status'], 'POST without CSRF token is rejected with 419');

$r = req($base, 'GET', '/items');
T::eq(1, substr_count($r['body'], '<!doctype'), '/items has exactly one doctype');

$r = req($base, 'GET', '/search?q=pixel');
T::eq(1, substr_count($r['body'], '<!doctype'), '/search has exactly one doctype');

$log = (string)@file_get_contents($sandbox . '/storage/logs/app.log');
T::ok(!str_contains($log, 'Unhandled exception'), 'no unhandled exceptions logged during the run');
T::ok(!str_contains($log, 'Call to undefined function'), 'no undefined-function errors during the run');

proc_terminate($proc);
proc_close($proc);

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    if ($f->getPathname() === $cookie) continue;
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@unlink($cookie);
@rmdir($sandbox);
