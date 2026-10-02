<?php
declare(strict_types=1);

use App\Core\Mailer;
use App\Services\HttpClient;
use Tests\T;

T::group('mailer/smtp-hardening');

T::ok(!Mailer::hostIsRoutable('127.0.0.1'), 'loopback SMTP host blocked');
T::ok(!Mailer::hostIsRoutable('localhost'), 'localhost SMTP host blocked');
T::ok(!Mailer::hostIsRoutable('169.254.169.254'), 'metadata SMTP host blocked');
T::ok(!Mailer::hostIsRoutable('10.0.0.1'), 'private SMTP host blocked');
T::ok(!Mailer::hostIsRoutable('192.168.0.5'), 'private SMTP host blocked (192.168)');
T::ok(!Mailer::hostIsRoutable(''), 'empty SMTP host blocked');
T::ok(Mailer::hostIsRoutable('smtp.gmail.com'), 'public SMTP host allowed');
T::ok(Mailer::hostIsRoutable('8.8.8.8'), 'public IP SMTP host allowed');

T::ok(!Mailer::headerSafe("a\r\nBcc: x@y.z"), 'CRLF in header blocked');
T::ok(!Mailer::headerSafe("a\nBcc: x@y.z"), 'LF in header blocked');
T::ok(!Mailer::headerSafe("a\0b"), 'NUL in header blocked');
T::ok(Mailer::headerSafe('PasteFilez Test'), 'normal display name allowed');

T::ok(!Mailer::addrSafe("a@b.c\r\nBcc: x@y.z"), 'CRLF in address blocked');
T::ok(!Mailer::addrSafe('not-an-email'), 'invalid address blocked');
T::ok(Mailer::addrSafe('user@example.com'), 'valid address allowed');

T::group('i18n/coverage');

$it = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/lang/it.json'), true);
$en = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/lang/en.json'), true);
T::ok(is_array($it) && is_array($en), 'both language files parse as JSON');
T::eq(array_keys($it), array_keys($en), 'it.json and en.json have identical key sets');
T::eq(count($it), count($en), 'same number of keys');

$used = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $src = (string)file_get_contents($f->getPathname());
    if (preg_match_all("/\bt\(\s*'([^']+)'/", $src, $m)) {
        foreach ($m[1] as $k) $used[$k][] = $f->getFilename();
    }
}
$missing = [];
$dynamic = [];
foreach (array_keys($used) as $k) {
    if (str_ends_with($k, '.')) { $dynamic[] = $k; continue; }
    if (!isset($it[$k])) $missing[] = $k;
}
sort($missing);
T::eq([], $missing, 'every static t() key used in app/ exists in lang/it.json');
T::ok(count($used) > 100, 'found a meaningful number of translation keys (' . count($used) . ')');
T::eq(['items.ai_status.'], $dynamic, 'the only dynamic key prefix is items.ai_status.');
foreach (['pending', 'done', 'processing', 'skipped', 'failed'] as $s) {
    T::ok(isset($it['items.ai_status.' . $s]), "items.ai_status.{$s} is translated");
}

T::group('views/no-full-documents');

$viewsRoot = dirname(__DIR__, 2) . '/app/Views/';
$standalone = ['install/index.php'];
$bad = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsRoot, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $path = $f->getPathname();
    $rel = ltrim(str_replace($viewsRoot, '', $path), '/');
    if (str_starts_with($rel, 'layouts/')) continue;
    if (in_array($rel, $standalone, true)) continue;
    $head = substr((string)file_get_contents($path), 0, 400);
    if (preg_match('/<!doctype|<html[\s>]/i', $head)) $bad[] = $rel;
}
T::eq([], $bad, 'no wrapped view is a full HTML document');
foreach ($standalone as $s) {
    T::ok(preg_match('/<!doctype|<html[\s>]/i', substr((string)file_get_contents($viewsRoot . $s), 0, 400)) === 1, "{$s} is a standalone document by design");
}
$idx = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/InstallController.php');
T::ok(str_contains($idx, "'install/index', [], false"), 'InstallController renders install/index without the layout');

T::group('no-dead-config');

T::ok(!is_file(dirname(__DIR__, 2) . '/config/config.php'), 'duplicate config/config.php is removed');
$appConfig = [];
foreach (get_declared_classes() as $c) {
    if (str_ends_with($c, '\\Config') && str_starts_with($c, 'App\\')) $appConfig[] = $c;
}
T::eq(['App\Core\Config'], $appConfig, 'exactly one App-namespaced Config class is loaded');
$global = array_filter(get_declared_classes(), fn($c) => !str_contains($c, '\\'));
T::ok(!in_array('Config', $global, true), 'no global Config class is loaded');

T::group('js-i18n-bridge');

foreach (['layouts/main.php', 'layouts/centered.php'] as $l) {
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Views/' . $l);
    T::contains($src, 'window.PZ_LANG', "{$l} defines window.PZ_LANG");
    T::contains($src, 'window.PZ_T', "{$l} aliases window.PZ_T");
    T::contains($src, 'JSON_HEX_TAG', "{$l} hex-escapes the injected JSON");
}

T::group('consistency/docs-match-the-code');

$root = dirname(__DIR__, 2);
$docs = [];
foreach (['README.md', 'AGENTS.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'CONTRIBUTING.en.md', 'SECURITY.md'] as $f) {
    $docs[$f] = (string)file_get_contents($root . '/' . $f);
}
$all = implode("\n", $docs);

// the number of assertions is the value most likely to go stale
preg_match_all('/\bT::(ok|eq|contains|notContains|throws)\s*\(/', implode("\n", array_map(
    static fn(string $p): string => (string)file_get_contents($p),
    glob($root . '/tests/suites/*.php') ?: []
)), $tc);
$real = count($tc[0]);
$suites = count(glob($root . '/tests/suites/*.php') ?: []);
if (preg_match_all('/(\d{2}) suite/i', $all, $sm)) {
    foreach (array_unique($sm[1]) as $claimedSuites) {
        T::eq($suites, (int)$claimedSuites, 'the suite count in the docs matches tests/suites/');
    }
}
if (preg_match_all('/(\d{3,5})\s+(?:asserzioni|assertions)/i', $all, $am)) {
    $claimed = (int)$am[1][1];
    T::ok(
        $claimed > $real && $claimed < $real * 4,
        'every doc claims the same assertion count (' . $claimed . '); ' . $real . ' literal call sites across ' . $suites . ' suites expand to that many at runtime'
    );
}

$indexSrc = (string)file_get_contents($root . '/public/index.php');
$realRoutes = preg_match_all('/^\$router->(get|post|put|delete)\(/mi', $indexSrc);
if (preg_match('/(\d{2,3}) route\b/i', $all, $rm)) {
    T::eq($realRoutes, (int)$rm[1], 'the route count in the docs matches public/index.php');
}
$dbSrc = (string)file_get_contents($root . '/app/Core/Db.php');
$realTables = preg_match_all('/CREATE TABLE/i', $dbSrc);
$realDataTables = $realTables - preg_match_all('/CREATE TABLE IF NOT EXISTS schema_meta/i', $dbSrc);
if (preg_match('/(\d{2}) tabelle\b/i', $all, $tm)) {
    T::eq($realDataTables, (int)$tm[1], 'the data-table count in the docs matches app/Core/Db.php (schema_meta counted separately)');
}

// documented API endpoints must all exist
preg_match_all('#^\s*`?(?:GET|POST|PUT|DELETE)\s+(/api/v1\S*?)`?#m', $docs['README.md'], $apiDoc);
preg_match_all("#\\\$router->(?:get|post|put|delete)\('(/api/v1[^']+)'#", $indexSrc, $apiReal);
$phantom = array_values(array_unique(array_filter(array_map(
    static fn(string $p): string => rtrim(preg_replace('/[?`].*$/', '', $p), '/'),
    $apiDoc[1]
), static fn(string $p): bool => $p !== '' && $p !== '/api/v1' && !in_array($p, $apiReal[1], true))));
T::eq([], $phantom, 'README documents no /api/v1 endpoint that does not exist');

// method names cited in the docs must exist
$cited = [];
if (preg_match_all('/`([A-Z][A-Za-z]+)::([a-z][A-Za-z0-9_]*)\(\)`/', $all, $cm)) {
    foreach ($cm[1] as $i => $cls) {
        $fq = str_contains($cls, '\\') ? $cls : 'App\\' . (str_ends_with($cls, 'Service') || str_ends_with($cls, 'Controller') ? 'Services\\' . $cls : 'Core\\' . $cls);
        foreach (['App\\Core\\' . $cls, 'App\\Services\\' . $cls, 'App\\Controllers\\' . $cls, 'App\\Models\\' . $cls, 'App\\Views\\' . $cls] as $cand) {
            if (class_exists($cand)) { $fq = $cand; break; }
        }
        if (class_exists($fq) || interface_exists($fq)) {
            $cited[$fq . '::' . $cm[2][$i]] = method_exists($fq, $cm[2][$i]);
        }
    }
}
foreach ($cited as $ref => $exists) {
    T::ok($exists, 'the docs cite ' . $ref . '(), which exists');
}
T::ok(count($cited) >= 10, 'the method-existence check inspected the docs (' . count($cited) . ' references)');

// the English and Italian contributing guides must not drift apart
$it = (string)file_get_contents($root . '/CONTRIBUTING.md');
$en = (string)file_get_contents($root . '/CONTRIBUTING.en.md');
preg_match('/(\d{3,5})\s+asserzioni/i', $it, $i1);
preg_match('/(\d{3,5})\s+assertions/i', $en, $i2);
T::eq($i1[1] ?? '', $i2[1] ?? '', 'both contributing guides quote the same assertion count');
foreach (['pdo_sqlite', 'gd', 'mbstring', 'curl', 'sodium', 'openssl', 'fileinfo', 'zip', 'intl'] as $ext) {
    T::ok(str_contains($it, $ext), 'CONTRIBUTING.md lists the ' . $ext . ' extension');
    T::ok(str_contains($en, $ext), 'CONTRIBUTING.en.md lists the ' . $ext . ' extension');
}
T::ok(!str_contains($it, 'sqlite3`'), 'CONTRIBUTING.md does not require sqlite3 (the code only uses PDO)');
