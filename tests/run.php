<?php
declare(strict_types=1);

require __DIR__ . '/../app/Core/autoload.php';
require __DIR__ . '/T.php';

use Tests\T;

$root = dirname(__DIR__);

$tmp = sys_get_temp_dir() . '/pf_test_' . getmypid();
if (is_dir($tmp)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($tmp);
}
@mkdir($tmp, 0750, true);
putenv('DB_PATH=' . $tmp . '/test.sqlite');
putenv('STORAGE_PATH=' . $tmp);
putenv('MASTER_KEY_PATH=' . $tmp . '/.key');
$_ENV['DB_PATH'] = $tmp . '/test.sqlite';
$_ENV['STORAGE_PATH'] = $tmp;
$_ENV['MASTER_KEY_PATH'] = $tmp . '/.key';
foreach (['APP_DEBUG' => 'false', 'APP_ENV' => 'test', 'ENCRYPT_FILES_AT_REST' => 'false'] as $k => $v) {
    putenv($k . '=' . $v);
    $_ENV[$k] = $v;
}

$guard = static function (string $resolved, string $label) use ($root, $tmp): void {
    $real = realpath(dirname($resolved));
    if ($real === false) return;
    $prod = realpath($root . '/storage');
    if ($prod === false) return;
    if (str_starts_with($real . '/', $prod . '/')) {
        fwrite(STDERR, "\nREFUSING TO RUN: {$label} resolves inside the production storage directory.\n  resolved: {$real}\n  prod:     {$prod}\n  Tests would corrupt real user data. Aborting.\n\n");
        exit(2);
    }
    if (!str_starts_with($real . '/', realpath($tmp) . '/')) {
        fwrite(STDERR, "\nREFUSING TO RUN: {$label} resolves outside the test sandbox.\n  resolved: {$real}\n  sandbox:  " . realpath($tmp) . "\n\n");
        exit(2);
    }
};

$guard((string)\App\Core\Config::get('db_path'), 'DB_PATH');
$guard((string)\App\Core\Config::get('master_key_path'), 'MASTER_KEY_PATH');
$guard((string)\App\Core\Config::get('storage_path') . '/x', 'STORAGE_PATH');

if (is_file($root . '/storage/app.sqlite')) {
    $before = md5_file($root . '/storage/app.sqlite') ?: '';
    register_shutdown_function(static function () use ($root, $before): void {
        $after = is_file($root . '/storage/app.sqlite') ? (md5_file($root . '/storage/app.sqlite') ?: '') : '';
        if ($after !== $before) {
            fwrite(STDERR, "FATAL: the test run modified the production database {$root}/storage/app.sqlite\n");
            exit(3);
        }
    });
}

\App\Core\Installer::generateMasterKey();
\App\Core\Db::init();

function mkUser(string $email, string $role = 'user', string $pwd = 'Str0ngPassw0rd!23'): int
{
    $existing = \App\Core\Db::first('SELECT id FROM users WHERE email = ?', [strtolower($email)]);
    if ($existing) return (int)$existing['id'];
    return \App\Core\Db::insert('users', [
        'email' => strtolower($email),
        'password_hash' => \App\Core\Crypto::hash($pwd),
        'role' => $role,
        'display_name' => $email,
        'language' => 'it',
        'theme' => 'dark',
        'storage_backend' => 'local',
        'storage_quota_mb' => 10240,
        'is_active' => 1,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
}

function mkItem(int $userId, string $name = 'f.txt', string $mime = 'text/plain', string $body = 'hello'): int
{
    $stored = \App\Services\StorageService::put(writeTmp($body), $name, $userId, false);
    return (int)\App\Core\Db::insert('items', [
        'user_id' => $userId,
        'folder_id' => null,
        'type' => 'file',
        'mime' => $mime,
        'original_name' => $name,
        'storage_backend' => 'local',
        'storage_path' => $stored['path'],
        'size' => $stored['size'],
        'sha256' => $stored['sha256'],
        'is_encrypted' => 0,
        'thumbnail_path' => null,
        'ai_status' => 'done',
        'is_public' => 0,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
}

function writeTmp(string $body): string
{
    $p = tempnam(sys_get_temp_dir(), 'pft');
    file_put_contents($p, $body);
    return $p;
}

function uploader(string $tmpPath, string $name, string $type = 'application/octet-stream'): array
{
    return ['name' => $name, 'type' => $type, 'tmp_name' => $tmpPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpPath)];
}


$files = glob(__DIR__ . '/suites/*.php');
sort($files);
foreach ($files as $f) {
    echo "\n== " . basename($f, '.php') . " ==\n";
    require $f;
}

exit(T::report());
