<?php
declare(strict_types=1);

use App\Core\Db;
use Tests\T;

T::group('contributor-guardrails');

$root = dirname(__DIR__, 2);
$indexSrc = (string)file_get_contents($root . '/public/index.php');

// --- 1. every registered route points at a controller method that exists ---
preg_match_all(
    '/\$router->(get|post|put|delete|any)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[([A-Za-z0-9_]+)::class\s*,\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\]\s*\)/',
    $indexSrc,
    $routeMatches,
    PREG_SET_ORDER
);
T::ok(count($routeMatches) >= 90, 'the route table was parsed out of public/index.php', 'found ' . count($routeMatches));

preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);$/m', $indexSrc, $useMatches);
$imports = [];
foreach ($useMatches[1] ?? [] as $fq) {
    $parts = explode('\\', $fq);
    $imports[end($parts)] = $fq;
}
$missingHandler = [];
$seenRoutes = [];
$duplicateRoutes = [];
foreach ($routeMatches as $m) {
    [, $verb, $path, $class, $action] = $m;
    $key = $verb . ' ' . $path;
    if (isset($seenRoutes[$key])) $duplicateRoutes[] = $key;
    $seenRoutes[$key] = true;
    $fqcn = $imports[$class] ?? ('App\\Controllers\\' . $class);
    if (!class_exists($fqcn)) {
        $missingHandler[] = $key . ' -> ' . $class . ' (class missing)';
        continue;
    }
    if (!method_exists($fqcn, $action)) {
        $missingHandler[] = $key . ' -> ' . $class . '::' . $action . '() (method missing)';
    }
}
T::eq([], $missingHandler, 'every route resolves to an existing controller method (a typo here is a fatal 500)');
T::eq([], $duplicateRoutes, 'no route is registered twice (the first one silently shadows the second)');

// --- 2. the router matches the way we think it does ---
$router = new \App\Core\Router();
$hit = null;
$router->get('/probe/{id}/edit', [ProbeController::class, 'edit']);
$router->post('/probe/{id}/edit/save', [ProbeController::class, 'save']);
foreach ([
    ['/probe/42/edit', 'edit', ['id' => '42']],
    ['/probe/42/edit/', 'edit', ['id' => '42']],
    ['/probe/abc/edit', 'edit', ['id' => 'abc']],
    ['/probe/42/edit/save', 'save', ['id' => '42']],
] as [$path, $expectedAction, $expectedParams]) {
    $matched = null;
    foreach ((function ($r): array {
        $ref = new \ReflectionClass($r);
        $p = $ref->getProperty('routes');
        $p->setAccessible(true);
        return $p->getValue($r);
    })($router) as $r) {
        if (!preg_match($r['pattern'], rtrim($path, '/') ?: '/', $mm)) continue;
        if ($r['method'] !== 'GET' && $r['method'] !== 'POST') continue;
        $matched = [$r['method'], $r['handler'][1], array_filter($mm, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY)];
        break;
    }
    T::ok($matched !== null, 'router matches ' . $path);
    if ($matched === null) continue;
    T::eq($expectedAction, $matched[1], $path . ' dispatches to ' . $expectedAction);
    if ($path === '/probe/42/edit') T::eq($expectedParams, $matched[2], 'router extracts the {id} segment as a string param');
}

// --- 3. every t() key used in app/ exists in every lang file ---
$langFiles = glob($root . '/lang/*.json') ?: [];
T::ok(count($langFiles) >= 2, 'at least it and en are shipped');
$usedKeys = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $src = (string)file_get_contents($f->getPathname());
    if (preg_match_all("/\bt\(\s*'([a-z0-9_]+(?:\.[a-z0-9_]+)*)'\s*\)/i", $src, $km)) {
        foreach ($km[1] as $k) $usedKeys[$k] = true;
    }
}
T::ok(count($usedKeys) > 100, 't() keys were harvested from app/', 'found ' . count($usedKeys));
foreach ($langFiles as $lf) {
    $table = json_decode((string)file_get_contents($lf), true);
    T::ok(is_array($table), basename($lf) . ' is valid JSON');
    if (!is_array($table)) continue;
    $absent = array_values(array_diff(array_keys($usedKeys), array_keys($table)));
    T::eq([], $absent, basename($lf) . ' defines every t() key used in app/');
}

// --- 4. every flash key set by a controller is a real translation key ---
$flashKeys = [];
foreach (glob($root . '/app/Controllers/*.php') ?: [] as $f) {
    $src = (string)file_get_contents($f);
    if (preg_match_all("/Session::set\(\s*'flash_(?:error|success|warning|info)'\s*,\s*'([a-z0-9_.]+)'/i", $src, $fm)) {
        foreach ($fm[1] as $k) $flashKeys[$k] = true;
    }
}
T::ok(count($flashKeys) > 0, 'flash keys were harvested from the controllers', 'found ' . count($flashKeys));
foreach ($langFiles as $lf) {
    $table = json_decode((string)file_get_contents($lf), true);
    if (!is_array($table)) continue;
    $raw = array_values(array_diff(array_keys($flashKeys), array_keys($table)));
    T::eq([], $raw, basename($lf) . ' translates every flash key (otherwise the user reads "last_admin")');
}

// --- 5. every view referenced by $this->view() exists on disk ---
$missingViews = [];
foreach (glob($root . '/app/Controllers/*.php') ?: [] as $f) {
    $src = (string)file_get_contents($f);
    if (!preg_match_all('/\$this->view\(\s*[\'"]([^\'"]+)[\'"]/', $src, $vm)) continue;
    foreach ($vm[1] as $tpl) {
        if (!is_file($root . '/app/Views/' . $tpl . '.php')) $missingViews[] = basename($f) . ' -> ' . $tpl;
    }
}
T::eq([], $missingViews, 'every $this->view() template exists (a typo returns HTTP 200 and a blank page)');

// --- 6. a job type nobody handles must fail loudly, not silently succeed ---
$before = (int)Db::value('SELECT COUNT(*) FROM jobs');
$badId = \App\Services\JobService::enqueue('definitely_not_a_job_type', 1, ['item_id' => 1]);
\App\Services\JobService::process(5);
$job = Db::first('SELECT status, last_error FROM jobs WHERE id = ?', [$badId]);
T::ok($job !== null, 'the bogus job was persisted before processing');
T::eq('failed', (string)($job['status'] ?? ''), 'an unknown job type ends in failed, never silently in done');
T::ok(($job['last_error'] ?? '') !== '', 'the failure records why (an unhandled job type is named in last_error)');
T::eq($before + 1, (int)Db::value('SELECT COUNT(*) FROM jobs'), 'no extra job rows were created while processing');

final class ProbeController extends \App\Controllers\Controller
{
    public function edit(array $params): void {}
    public function save(array $params): void {}
}