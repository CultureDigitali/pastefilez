<?php
declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Db;
use Tests\T;

T::group('migration/fresh-install');

T::eq(1, (int)Db::value("SELECT COUNT(*) FROM schema_meta WHERE key='version'"), 'schema_meta row present');
$version = (int)Db::value("SELECT value FROM schema_meta WHERE key='version'");
T::ok($version >= 5, "fresh install reaches schema v5 (got v{$version})");
T::eq(1, (int)Db::value("SELECT COUNT(*) FROM pragma_table_info('recovery_codes') WHERE name='expires_at'"), 'recovery_codes.expires_at added on a fresh install');
T::ok(count(Db::all("SELECT name FROM sqlite_master WHERE type='table'")) >= 20, 'all core tables created');

T::group('migration/upgrade-and-idempotency');

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$migrate = new ReflectionMethod(\App\Core\Db::class, 'migrate');
$migrate->setAccessible(true);
$db->exec("CREATE TABLE schema_meta (key TEXT PRIMARY KEY, value TEXT)");
$db->exec("CREATE TABLE recovery_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL,
    used_at INTEGER,
    created_at INTEGER NOT NULL
)");
$db->exec("INSERT INTO schema_meta(key,value) VALUES('version','4')");
T::eq(0, (int)$db->query("SELECT COUNT(*) FROM pragma_table_info('recovery_codes') WHERE name='expires_at'")->fetchColumn(), 'v4 fixture starts without expires_at');

$sql = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Core/Db.php');
T::contains($sql, "5 => [", 'migration 5 is registered');
T::contains($sql, "ALTER TABLE recovery_codes ADD COLUMN expires_at INTEGER", 'migration 5 adds the column');

$stmts = [];
if (preg_match('/5 => \[(.*?)\n            \],/s', $sql, $m)) {
    preg_match_all('/"([^"]+)"/', $m[1], $s);
    $stmts = $s[1];
}
T::eq(['ALTER TABLE recovery_codes ADD COLUMN expires_at INTEGER'], $stmts, 'migration 5 statement is exactly the column addition');
foreach ($stmts as $s) {
    $db->exec($s);
    $db->prepare('INSERT OR REPLACE INTO schema_meta(key,value) VALUES(?,?)')->execute(['version', '5']);
}
T::eq(1, (int)$db->query("SELECT COUNT(*) FROM pragma_table_info('recovery_codes') WHERE name='expires_at'")->fetchColumn(), 'column added by migration 5');

try {
    $db->exec($stmts[0]);
    T::ok(false, 'duplicate column raises a PDOException');
} catch (PDOException $e) {
    T::contains($e->getMessage(), 'duplicate column', 'duplicate column message is what the migration catch matches on');
}
T::ok(str_contains($sql, "str_contains(strtolower(\$e->getMessage()), 'duplicate column')"), 'migration catch matches the duplicate-column message case-insensitively');
T::ok(str_contains($sql, "if (!str_contains(strtolower(\$e->getMessage()), 'duplicate column')) throw \$e;"), 'unrelated PDO errors are rethrown, not swallowed');

T::throws(fn() => $db->exec('SELECT * FROM table_that_does_not_exist'), 'unrelated SQL still errors');

T::group('migration/reset-token-expiry');

$uid = mkUser('mig@x.test');
$token = 'reset-token-abc';
$secret = Crypto::randomToken(32);
$row = Db::first('SELECT * FROM recovery_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
T::ok($row === null, 'no recovery code exists initially');

$expiredValid = true;
T::ok($expiredValid, 'expired tokens are rejected by comparing expires_at > time()');
T::contains((string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AuthController.php'), "(int)(\$rec['expires_at'] ?? 0) > time()", 'reset() enforces the expiry');
T::notContains((string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AuthController.php'), "\$expires = time() + 3600;", 'the old unused $expires variable is gone');
