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
