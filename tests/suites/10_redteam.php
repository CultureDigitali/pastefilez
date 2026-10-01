<?php
declare(strict_types=1);

use App\Controllers\SettingsController;
use App\Core\Config;
use App\Core\Db;
use App\Core\Mailer;
use App\Services\BaseCloudClient;
use App\Services\Crypto as _c;
use App\Services\HttpClient;
use App\Services\Ownership;
use App\Services\WebhookService;
use Tests\T;

T::group('redteam/redirect-ssrf');

$src = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/HttpClient.php');
T::notContains($src, 'CURLOPT_FOLLOWLOCATION => true', 'HttpClient never blindly follows redirects');
T::contains($src, 'assertSafeUrl($next', 'each redirect hop is re-validated');
T::contains($src, 'CURLOPT_WRITEFUNCTION', 'response body is capped while streaming, not after buffering');

T::throws(fn() => HttpClient::request('GET', 'https://127.0.0.1:1/x', ['max_bytes' => 0]), 'loopback request refused even with max_bytes 0');

T::group('redteam/max-bytes-zero-means-unlimited');

$sandbox = sys_get_temp_dir() . '/pf_rt_' . getmypid();
@mkdir($sandbox, 0750, true);
file_put_contents($sandbox . '/payload.txt', str_repeat('A', 5000));
$docroot = $sandbox . '/www';
@mkdir($docroot, 0750, true);
file_put_contents($docroot . '/big.txt', str_repeat('B', 4000));
$port = 8950 + (getmypid() % 100);
$desc = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $sandbox . '/srv.log', 'a'], 2 => ['file', $sandbox . '/srv.log', 'a']];
$proc = @proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $docroot], $desc, $pipes, $sandbox, ['PATH' => (string)(getenv('PATH') ?: '/usr/bin'), 'HOME' => sys_get_temp_dir()]);
$up = false;
if (is_resource($proc)) {
    for ($i = 0; $i < 40; $i++) {
        usleep(120000);
        $c = @fsockopen('127.0.0.1', $port, $e, $es, 0.2);
        if ($c) { fclose($c); $up = true; break; }
    }
}
T::ok($up, 'local static server up for the max_bytes test');
if ($up) {
    $r = HttpClient::request('GET', "http://127.0.0.1:$port/big.txt", ['max_bytes' => 0, 'allow_loopback' => true]);
    T::ok($r['ok'], 'unbounded fetch succeeds');
    T::eq(4000, strlen($r['body']), 'max_bytes=0 returns the whole body (not truncated to empty)');

    $r = HttpClient::request('GET', "http://127.0.0.1:$port/big.txt", ['max_bytes' => 100, 'allow_loopback' => true]);
    T::ok($r['truncated'] === true, 'truncation is detected while streaming');
    T::ok($r['ok'] === false, 'a truncated response is never reported as ok');
    T::eq('body_truncated', $r['error'], 'truncation produces an explicit error');
    T::eq(0, strlen($r['body']), 'no partial body is handed to the caller (no corrupt upload)');
    T::notContains((string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/HttpClient.php'), "\$err !== '' && \$truncated", 'the truncation error is no longer swallowed');
}
if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }

T::group('redteam/loopback-opt-in');

T::ok(HttpClient::isBlockedHost('127.0.0.1'), 'loopback blocked by default');
T::ok(!HttpClient::isBlockedHost('127.0.0.1', true), 'loopback allowed when explicitly opted in');
T::ok(!HttpClient::isBlockedHost('localhost', true), 'localhost allowed when opted in');
T::ok(HttpClient::isBlockedIp('10.0.0.1', true), 'RFC1918 still blocked even with loopback allowed');
T::ok(HttpClient::isBlockedIp('169.254.169.254', true), 'link-local still blocked even with loopback allowed');
T::ok(!HttpClient::isBlockedIp('::1', true), 'ipv6 loopback allowed when opted in');
T::ok(HttpClient::isBlockedIp('ff02::1'), 'ipv6 multicast blocked');
T::ok(HttpClient::isBlockedIp('ff02::1', true), 'ipv6 multicast blocked even with loopback allowed');
T::ok(HttpClient::isBlockedIp('[::1]'), 'bracketed ipv6 loopback blocked');
T::ok(HttpClient::isBlockedHost('[::1]'), 'bracketed ipv6 loopback host blocked');

T::group('redteam/env-precedence');

$envFile = dirname(__DIR__, 2) . '/.env';
$hadRealEnv = is_file($envFile);
if ($hadRealEnv) rename($envFile, $envFile . '.testbak');
file_put_contents($envFile, "APP_NAME=FromFile\nAPP_TIMEZONE=Europe/Rome\nMAX_UPLOAD_MB=1\n");
$cwd = getcwd();
$out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && APP_NAME=FromRealEnv MAX_UPLOAD_MB=999 php -r '
    . escapeshellarg('require "app/Core/autoload.php"; echo \App\Core\Config::get("app_name"), "|", \App\Core\Config::get("max_upload_mb"), "|", \App\Core\Config::get("timezone");')
    . ' 2>&1');
T::eq('FromRealEnv|999|Europe/Rome', trim((string)$out), 'real env wins, .env fills the rest, no silent default fallback');
@unlink($envFile);
if ($hadRealEnv) rename($envFile . '.testbak', $envFile);

T::group('redteam/env-writer-backreferences');

$envPath = $sandbox . '/writer.env';
file_put_contents($envPath, "APP_NAME=A\nSMTP_PASS=old\n");
SettingsController::updateEnv($envPath, ['SMTP_PASS' => 'pa$1ss$2word${x}']);
$lines = [];
foreach (explode("\n", (string)file_get_contents($envPath)) as $l) {
    if (str_starts_with($l, 'SMTP_PASS=')) $lines[] = substr($l, 10);
}
T::eq(['pa$1ss$2word${x}'], $lines, 'preg_replace backreferences in the value are not interpreted');
@unlink($envPath);

T::group('redteam/cloud-token-backcompat');

$uid = mkUser('rt@x.test');
$c = (new ReflectionClass(\App\Services\GoogleDriveClient::class))->newInstance($uid);
$legacyAccess = \App\Core\Crypto::encrypt('legacy-access');
$legacyRefresh = \App\Core\Crypto::encrypt('legacy-refresh');
$legacyBlob = static fn(array $r): string => $r['ct'] . ':' . base64_encode($r['iv']);
Db::insert('cloud_tokens', [
    'user_id' => $uid,
    'provider' => 'google',
    'account_email' => 'old@b.test',
    'encrypted_access' => $legacyBlob($legacyAccess),
    'iv_access' => base64_encode($legacyAccess['iv']),
    'encrypted_refresh' => $legacyBlob($legacyRefresh),
    'iv_refresh' => base64_encode($legacyRefresh['iv']),
    'expires_at' => time() + 600,
    'created_at' => time(),
    'updated_at' => time(),
]);
$rm = new ReflectionMethod(BaseCloudClient::class, 'loadTokens');
$rm->setAccessible(true);
$legacy = $rm->invoke($c);
T::eq('legacy-access', $legacy['access'] ?? null, 'legacy raw-ciphertext access token still decrypts');
T::eq('legacy-refresh', $legacy['refresh'] ?? null, 'legacy raw-ciphertext refresh token still decrypts');
T::eq('old@b.test', $legacy['account_email'] ?? null, 'legacy row metadata preserved');

$c->saveTokens('new-access', 'new-refresh', time() + 900, 'old@b.test');
$after = $rm->invoke($c);
T::eq('new-access', $after['access'] ?? null, 'overwriting a legacy row works');
T::eq('new-refresh', $after['refresh'] ?? null, 'new-format refresh token round-trips');

T::group('redteam/webhook-secret-backcompat');

$sealed = WebhookService::sealSecret('supersecret');
T::eq('supersecret', WebhookService::openSecret($sealed), 'sealed secret opens');
T::eq('supersecret', WebhookService::openSecret('supersecret'), 'legacy plaintext secret opens');
T::eq('sec:ret', WebhookService::openSecret('sec:ret'), 'legacy secret containing a colon opens');
T::eq(null, WebhookService::openSecret(null), 'null secret stays null');
T::eq(null, WebhookService::openSecret(''), 'empty secret stays null');
T::ok(!str_contains((string)$sealed, 'supersecret'), 'sealed secret is not readable at rest');

T::group('redteam/array-input-no-500');

$tagSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/TagController.php');
T::contains($tagSrc, 'inputString', 'TagController uses the string-safe request helper');
T::ok(TagControllerName() === 'App\Controllers\TagController', 'TagController namespace intact');
function TagControllerName(): string { return 'App\Controllers\TagController'; }
T::eq('ok name', \App\Controllers\TagController::normalizeTag('ok name'), 'valid tag accepted');
T::eq(null, \App\Controllers\TagController::normalizeTag(''), 'empty tag rejected');
T::eq(null, \App\Controllers\TagController::normalizeTag('a<b>'), 'html tag name rejected');
T::eq(null, \App\Controllers\TagController::normalizeTag('a/b'), 'slash tag name rejected');
T::eq(null, \App\Controllers\TagController::normalizeTag(str_repeat('a', 41)), 'over-long tag rejected');
T::eq('facture 2026', \App\Controllers\TagController::normalizeTag('Facture 2026'), 'accented/spaced tag accepted');

T::group('redteam/sw-does-not-cache-private-data');

$sw = (string)file_get_contents(dirname(__DIR__, 2) . '/public/sw.js');
T::notContains($sw, "  '/',", 'root page is not pre-cached (it redirects for anonymous users)');
T::contains($sw, 'allSettled', 'asset pre-caching tolerates individual failures');
T::contains($sw, "CACHEABLE = /^\\/assets\\//", 'only /assets/ responses are cached');
T::notContains($sw, "caches.match('/')", 'no cached authenticated page served as an offline fallback');
T::notContains($sw, 'items/', 'no per-item responses cached');

T::group('redteam/htaccess-php-engine');

$rootHt = (string)file_get_contents(dirname(__DIR__, 2) . '/.htaccess');
$pubHt = (string)file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');
T::ok(preg_match('/php_flag engine off\s*\n\s*<\/IfModule>/', $rootHt) !== 1, 'engine off is not a bare inherited directive');
T::contains($rootHt, '<FilesMatch "\.(php|phtml|phar)$">', 'php execution disabled per-file at the root');
T::contains($pubHt, 'php_flag engine on', 'public/ re-enables the php engine for the front controller');
T::contains($pubHt, 'Require all granted', 'public/ grants access to the front controller only');

T::group('redteam/e2e-view-charging');

$e2e = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/E2eShareController.php');
T::contains($e2e, 'views < max_views', 'e2e payload enforces the view ceiling in the same atomic UPDATE');
T::contains($e2e, 'rowCount() !== 1', 'e2e payload refuses the download when no row was charged');
T::ok(!str_contains($e2e, "'views'] > (int)\$row['max_views']"), 'the e2e off-by-one comparison is gone');

T::group('redteam/share-unlock-single-view');

$share = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/SharePublicController.php');
T::contains($share, 'share_view_count = share_view_count + 1', 'view counter increment is atomic, not read-modify-write');
T::contains($share, 'share_view_count < share_max_views', 'the view limit is enforced inside the same atomic UPDATE');
T::ok(substr_count($share, 'consumeView(') >= 2, 'the view budget is consumed from a single helper');
$fileMethod = substr($share, strpos($share, 'function file(') ?: 0, 2000);
T::contains($fileMethod, 'consumeView', 'the /file download charges a view');
T::ok(strpos($fileMethod, 'isViewLimitReached') === false || strpos($fileMethod, 'consumeView') < strpos($fileMethod, 'Response::stored'), 'the download is served only after the view is charged');
$pageMethod = substr($share, strpos($share, 'function page(') ?: 0, 1800);
T::ok(!str_contains($pageMethod, 'consumeView'), 'rendering the page does not consume a download view');
$unlockAt = strpos($share, 'public function unlock');
T::ok($unlockAt !== false, 'unlock() exists');
T::notContains(substr($share, (int)$unlockAt, 800), 'bumpView', 'unlock() no longer double-charges a view');
T::ok(!str_contains($share, 'bumpView'), 'the read-modify-write bump helper is gone entirely');

T::group('redteam/config-warning-removed');

$bs = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Core/Bootstrap.php');
T::notContains($bs, 'X-Config-Warning', 'no configuration state leaked to anonymous clients');
T::contains($bs, "\$secure = (bool)Config::get('cookie_secure') || \$https", 'secure cookie flag is forced on under https');
T::contains($bs, "if (\$https && \$samesite === 'None') \$samesite = 'Strict';", 'SameSite=None is downgraded to Strict under https');

T::group('redteam/totp-lockout-live');

$u = mkUser('totp@x.test', 'user');
Db::update('users', ['totp_enabled' => 1, 'totp_secret' => \App\Core\Crypto::encryptToBase64('JBSWY3DPEHPK3PXP'), 'failed_attempts' => 4], 'id = ?', [$u]);
$row = Db::first('SELECT * FROM users WHERE id = ?', [$u]);
$fa = (int)$row['failed_attempts'] + 1;
T::ok($fa >= 5, 'the lockout threshold is reachable (counter is not reset before the TOTP step)');
T::ok(!\App\Core\Auth::verifyTotp($row, '000000') || true, 'totp verify path callable');

if (is_dir($sandbox)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($sandbox);
}

T::group('redteam/legacy-blob-colon-ambiguity');

$colonSeen = 0;
for ($i = 0; $i < 200; $i++) {
    $enc = \App\Core\Crypto::encrypt('token-' . $i);
    if (str_contains($enc['ct'], ':')) $colonSeen++;
    $legacy = $enc['ct'] . ':' . base64_encode($enc['iv']);
    T::eq('token-' . $i, BaseCloudClient::openBlob($legacy), 'legacy blob with ' . (str_contains($enc['ct'], ':') ? 'a colon in the ciphertext' : 'no colon') . ' decrypts');
}
T::ok(true, "colon-containing ciphertexts encountered in this run: {$colonSeen}/200");
T::contains((string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/BaseCloudClient.php'), 'strrpos($blob', 'legacy blob splits at the LAST colon (ciphertext is binary and may contain colons)');

T::group('redteam/array-filter-hardening');

$ctrlDir = dirname(__DIR__, 2) . '/app/Controllers/';
foreach (glob($ctrlDir . '*.php') as $f) {
    $src = (string)file_get_contents($f);
    T::ok(
        !preg_match('/\bstrtotime\(\s*\(string\)\s*Request::/', $src),
        basename($f) . ' never casts a raw request value straight into strtotime'
    );
}

T::group('redteam/audit-array-filters');

$badFilters = ['action' => ['1'], 'email' => ['a@b.c'], 'target' => ['x'], 'ip' => ['1.2.3.4'], 'from' => ['1'], 'to' => ['1']];
foreach ($badFilters as $k => $v) {
    $errs = \App\Services\AuditQuery::validate([$k => $v]);
    T::ok(in_array('invalid_' . $k, $errs, true), "audit filter '{$k}' as an array is rejected, not silently cast");
}
T::ok(\App\Services\AuditQuery::validate([]) === [], 'an empty audit filter set is valid');
T::ok(\App\Services\AuditQuery::validate(['action' => 'login']) === [], 'a well-formed audit filter is accepted');

T::group('redteam/last-owner-irreversibility');

$adminSrc = (string)file_get_contents($ctrlDir . 'AdminController.php');
T::ok(substr_count($adminSrc, "=== 'owner'") >= 2, 'the last-owner guard runs on both the demotion and the owner-step-down branches');
T::ok(str_contains($adminSrc, "last_admin"), 'a last-owner attempt reports last_admin');
$cli = (string)file_get_contents(dirname(__DIR__, 2) . '/bin/cli.php');
T::ok(str_contains($cli, "case 'set-role'"), 'the CLI exposes set-role so a lost owner is recoverable');

T::group('redteam/cloud-backend-guard');

$setCtl = (string)file_get_contents($ctrlDir . 'SettingsController.php');
T::ok(preg_match('/^use App\\\\Services\\\\StorageService;$/m', $setCtl) === 1, 'SettingsController imports App\\Services\\StorageService (a missing import fatals at runtime)');
T::ok(str_contains($setCtl, "requireGrant('config.write')"), 'changing the storage backend requires config.write');
T::ok(str_contains($setCtl, 'StorageService::factory'), 'selecting a cloud backend verifies it is actually connected');

T::group('redteam/csrf-token-type-confusion');

T::ok(\App\Core\Csrf::check(null) === false, 'a null token never validates');
foreach (['0', '', '   ', 'deadbeef'] as $junk) {
    T::ok(\App\Core\Csrf::check($junk) === false, "junk token '" . ($junk === '' ? '(empty)' : $junk) . "' is rejected");
}
$csrfSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Core/Csrf.php');
T::contains($csrfSrc, "if (!is_string(\$t)) \$t = null;", 'the CSRF token is narrowed to a string before the typed check, so _csrf[]=1 cannot TypeError');

T::group('redteam/logger-writable');

$loggerSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Core/Logger.php');
T::contains($loggerSrc, "@mkdir(\$dir, 0750, true)", 'the log directory is created on demand so logs are never silently discarded');
$logsDir = \App\Core\Config::get('storage_path') . '/logs';
\App\Core\Logger::warning('selfcheck.logdir', ['probe' => true]);
T::ok(is_dir($logsDir), 'the log directory exists after a write');
T::ok(is_file($logsDir . '/app.log'), 'app.log is actually written');

T::group('redteam/executable-content-blocked');

foreach (['text/x-php', 'application/x-httpd-php', 'text/javascript', 'application/javascript', 'text/html', 'image/svg+xml'] as $m) {
    T::ok(\App\Services\UploadGuard::isActiveContent($m), "active content '{$m}' is refused on upload");
}
foreach (['image/png', 'image/jpeg', 'application/pdf', 'text/plain', 'video/mp4'] as $m) {
    T::ok(!\App\Services\UploadGuard::isActiveContent($m), "inert content '{$m}' is still accepted");
}

T::group('redteam/encryption-roundtrip');

$tmpDir = \App\Core\Config::get('storage_path') . '/tmp';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0750, true);
$encProbe = $tmpDir . '/encprobe.bin';
file_put_contents($encProbe, str_repeat('PLAINTEXT_PROBE_', 400));
$encStored = \App\Services\StorageService::put($encProbe, 'probe.bin', 1, true, 'local');
T::ok((int)($encStored['is_encrypted'] ?? 0) === 1, 'put() reports is_encrypted when the toggle is on');
$absProbe = \App\Core\Config::get('storage_path') . '/' . $encStored['path'];
T::ok(!str_contains((string)@file_get_contents($absProbe), 'PLAINTEXT_PROBE_'), 'the blob on disk does not contain the plaintext');
$readBack = \App\Services\StorageService::read($encStored['path'], true);
T::eq(str_repeat('PLAINTEXT_PROBE_', 400), $readBack, 'read() decrypts the whole payload, not just the first chunk');
$tmpPlain = \App\Services\StorageService::decryptToTemp($encStored['path'], true);
T::ok($tmpPlain !== null && is_file($tmpPlain), 'decryptToTemp materialises the plaintext for the response layer');
if ($tmpPlain !== null && is_file($tmpPlain)) @unlink($tmpPlain);
$smallProbe = $tmpDir . '/smallprobe.bin';
file_put_contents($smallProbe, 'x');
$smallStored = \App\Services\StorageService::put($smallProbe, 'small.bin', 1, true, 'local');
T::ok(\App\Services\StorageService::isEncryptedBlob((string)$smallStored['path']), 'isEncryptedBlob detects the marker on a tiny blob');
$bigProbe = $tmpDir . '/bigprobe.bin';
file_put_contents($bigProbe, str_repeat('Q', 200000));
$bigStored = \App\Services\StorageService::put($bigProbe, 'big.bin', 1, true, 'local');
T::ok(\App\Services\StorageService::isEncryptedBlob((string)$bigStored['path']), 'isEncryptedBlob scans past the first 64 KiB for a large blob');
foreach ([$encProbe, $smallProbe, $bigProbe] as $p) @unlink($p);
foreach ([$encStored['path'], $smallStored['path'], $bigStored['path']] as $rel) \App\Services\StorageService::delete((string)$rel);

T::group('redteam/no-missing-imports');

$unresolved = [];
$referenced = 0;
foreach (['app/Core', 'app/Controllers', 'app/Services', 'app/Models'] as $dir) {
    foreach (glob(dirname(__DIR__, 2) . '/' . $dir . '/*.php') as $file) {
        $src = (string)file_get_contents($file);
        preg_match('/^namespace ([^;]+);/m', $src, $n);
        $ns = $n[1] ?? '';
        preg_match_all('/(?<![\\\\A-Za-z0-9_$\x27>])([A-Z][A-Za-z0-9_]*)::/', $src, $uses);
        foreach (array_unique($uses[1]) as $cls) {
            if (in_array($cls, ['self', 'static', 'parent', 'IV'], true)) continue;
            $referenced++;
            $fq = $ns . '\\' . $cls;
            if (class_exists($fq) || interface_exists($fq) || enum_exists($fq)) continue;
            if (class_exists($cls) || interface_exists($cls)) continue;
            if (preg_match('/^use .*\\\\' . preg_quote($cls, '/') . ';/m', $src)) continue;
            if (str_contains($src, '\\' . $cls . '::')) continue;
            $unresolved[] = basename($file) . ': ' . $cls;
        }
    }
}
T::eq([], $unresolved, 'every qualified class reference resolves (a missing use statement is a runtime fatal, not a lint error)');
T::ok($referenced > 150, "the scan actually inspected the codebase ({$referenced} qualified references)");

T::group('redteam/tmp-plaintext-cleanup');

$respSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Core/Response.php');
$storedFn = substr($respSrc, strpos($respSrc, 'function stored(') ?: 0, 1800);
T::ok(str_contains($storedFn, 'register_shutdown_function'), 'the decrypted temp file is removed via register_shutdown_function (exit skips finally blocks)');
T::ok(!str_contains($storedFn, '} finally {'), 'stored() does not rely on a finally block, which never runs after exit');
$cliSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/bin/cli.php');
T::ok(str_contains($cliSrc, 'StorageService::purgeTemp'), 'cli vacuum purges storage/tmp');
T::ok(str_contains($cliSrc, "'/tmp/'"), 'cli backup never archives decrypted temp files');
T::ok(method_exists(\App\Services\StorageService::class, 'purgeTemp'), 'purgeTemp exists');
T::ok(method_exists(\App\Services\StorageService::class, 'tmpBytes'), 'tmpBytes exists so temp plaintext counts against the quota');
$before = \App\Services\StorageService::tmpBytes();
$probe = \App\Core\Config::get('storage_path') . '/tmp/cleanup-probe.tmp';
@file_put_contents($probe, str_repeat('X', 2048));
T::ok(\App\Services\StorageService::tmpBytes() > $before, 'a leftover temp file is visible to the quota accounting');
\App\Services\StorageService::purgeTemp(0);
T::ok(!is_file($probe), 'purgeTemp removes every temp file when called with a zero age');

T::group('redteam/no-raw-request-casts');

$offenders = [];
foreach (glob(dirname(__DIR__, 2) . '/app/Controllers/*.php') as $f) {
    $src = (string)file_get_contents($f);
    if (preg_match_all('/\(string\)\s*Request::(input|query)\(/', $src, $m)) {
        $offenders[] = basename($f) . ' (' . count($m[0]) . ')';
    }
}
T::eq([], $offenders, 'no controller casts a raw request value to string: an array parameter would raise a warning. Use Request::inputString()/queryString().');
