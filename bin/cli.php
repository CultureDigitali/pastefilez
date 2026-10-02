<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run from CLI.\n");
    exit(1);
}

require __DIR__ . '/../app/Core/autoload.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Installer;
use App\Core\Logger;
use App\Models\Setting;
use App\Services\JobService;
use App\Services\StorageService;

$argv = $_SERVER['argv'] ?? [];
array_shift($argv);
$cmd = $argv[0] ?? 'help';
$args = array_slice($argv, 1);

function cli_out(string $s): void { echo $s . "\n"; }
function cli_err(string $s): void { fwrite(STDERR, $s . "\n"); }
function cli_prompt(string $q): string {
    echo $q . ': ';
    static $h = null;
    if ($h === null) $h = fopen('php://stdin', 'r');
    $line = trim((string)fgets($h));
    return $line;
}

switch ($cmd) {
    case 'help':
        cli_out("PasteFilez CLI");
        cli_out("Comandi:");
        cli_out("  install                 - Installazione interattiva");
        cli_out("  create-user <email>     - Crea utente (genera password)");
        cli_out("  reset-password <email>  - Reset password utente");
        cli_out("  set-role <email> <role> - Imposta il ruolo (recovery owner perso)");
        cli_out("  list-users              - Lista utenti");
        cli_out("  rotate-master-key       - Ruota la master key (ri-cifra API keys e token cloud)");
        cli_out("  backup <dest.zip>       - Backup completo (db + files cifrati)");
        cli_out("  process-jobs [n]        - Processa n job AI dalla coda (default 10)");
        cli_out("  vacuum                  - Vacuum SQLite + pulizia log");
        cli_out("  purge-trash [days]      - Elimina elementi nel cestino più vecchi di N giorni (default 30)");
        cli_out("  set <key> <value>       - Imposta una variabile d'ambiente in .env");
        break;

    case 'install':
        Config::load();
        if (Installer::isInstalled()) { cli_err("Già installato."); exit(1); }
        $email = cli_prompt("Email admin");
        $name = cli_prompt("Nome (opzionale)");
        $pwd = cli_prompt("Password (min 12)");
        if (strlen($pwd) < 12) { cli_err("Password troppo corta."); exit(1); }
        Installer::generateMasterKey();
        $id = Installer::createAdmin($email, $pwd, $name ?: $email);
        cli_out("Admin creato (id=$id).");
        break;

    case 'create-user':
        Config::load();
        if (!Installer::isInstalled()) { cli_err("Non installato. Esegui 'install'."); exit(1); }
        Db::init();
        $email = $args[0] ?? cli_prompt("Email");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { cli_err("Email non valida."); exit(1); }
        if (Db::first('SELECT id FROM users WHERE email=?', [$email])) { cli_err("Esiste già."); exit(1); }
        $pwd = bin2hex(random_bytes(8)) . 'Aa1!';
        $id = Db::insert('users', [
            'email' => $email,
            'password_hash' => Crypto::hash($pwd),
            'role' => 'user',
            'language' => 'it',
            'theme' => 'dark',
            'storage_backend' => 'local',
            'storage_quota_mb' => (int)Config::get('quota_per_user_mb', 10240),
            'is_active' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        cli_out("Creato utente id=$id. Password: $pwd");
        break;

    case 'reset-password':
        Config::load();
        if (!Installer::isInstalled()) { cli_err("Non installato."); exit(1); }
        Db::init();
        $email = $args[0] ?? cli_prompt("Email");
        $u = Db::first('SELECT * FROM users WHERE email=?', [$email]);
        if (!$u) { cli_err("Non trovato."); exit(1); }
        $pwd = bin2hex(random_bytes(8)) . 'Aa1!';
        Db::update('users', ['password_hash' => Crypto::hash($pwd), 'failed_attempts' => 0, 'locked_until' => null, 'updated_at' => time()], 'id=?', [$u['id']]);
        cli_out("Nuova password per {$email}: $pwd");
        break;

    case 'set-role':
        Config::load();
        if (!Installer::isInstalled()) { cli_err("Non installato."); exit(1); }
        Db::init();
        $email = $args[0] ?? cli_prompt("Email");
        $role = strtolower((string)($args[1] ?? ''));
        if (!\App\Models\Role::exists($role)) { cli_err("Ruolo non valido. Validi: " . implode(', ', array_keys(\App\Models\Role::all()))); exit(1); }
        $u = Db::first('SELECT * FROM users WHERE email=?', [$email]);
        if (!$u) { cli_err("Non trovato."); exit(1); }
        Db::update('users', ['role' => $role, 'updated_at' => time()], 'id=?', [$u['id']]);
        \App\Core\Audit::log('cli.role.change', 'user:' . $u['id'], ['to' => $role]);
        cli_out("Ruolo di {$email} impostato a {$role}");
        break;

    case 'list-users':
        Config::load();
        Db::init();
        $rows = Db::all('SELECT id, email, role, is_active, last_login_at FROM users ORDER BY id');
        foreach ($rows as $r) {
            cli_out(sprintf("#%d  %s  %s  %s  last=%s", $r['id'], $r['email'], $r['role'], $r['is_active']?'active':'disabled', $r['last_login_at'] ?? 'never'));
        }
        break;

    case 'rotate-master-key':
        Config::load();
        if (!Installer::isInstalled()) { cli_err("Non installato."); exit(1); }
        Db::init();
        $oldKey = Crypto::masterKey();
        $items = [];
        foreach (Db::all('SELECT id, encrypted_key FROM api_keys') as $r) {
            $plain = Crypto::decryptFromBase64($r['encrypted_key'], $oldKey);
            if ($plain !== null) $items[] = ['table' => 'api_keys', 'id' => $r['id'], 'plain' => $plain];
        }
        foreach (Db::all('SELECT id, encrypted_access, encrypted_refresh, iv_access, iv_refresh FROM cloud_tokens') as $r) {
            $acc = selfDecrypt($r['encrypted_access'], $r['iv_access'], $oldKey);
            $ref = $r['encrypted_refresh'] ? selfDecrypt($r['encrypted_refresh'], $r['iv_refresh'], $oldKey) : null;
            if ($acc !== null) $items[] = ['table' => 'cloud_tokens', 'id' => $r['id'], 'access' => $acc, 'refresh' => $ref];
        }
        Installer::generateMasterKey();
        $newKey = Crypto::masterKey();
        foreach ($items as $it) {
            if ($it['table'] === 'api_keys') {
                $enc = Crypto::encryptToBase64($it['plain'], $newKey);
                Db::update('api_keys', ['encrypted_key' => $enc], 'id=?', [$it['id']]);
            } else {
                $a = Crypto::encrypt($it['access'], $newKey);
                $r = $it['refresh'] ? Crypto::encrypt($it['refresh'], $newKey) : null;
                Db::update('cloud_tokens', [
                    'encrypted_access' => $a['ct'],
                    'iv_access' => $a['iv'],
                    'encrypted_refresh' => $r['ct'] ?? null,
                    'iv_refresh' => $r['iv'] ?? null,
                ], 'id=?', [$it['id']]);
            }
        }
        cli_out("Master key ruotata. " . count($items) . " elementi ri-cifrati.");
        break;

    case 'backup':
        Config::load();
        $dest = $args[0] ?? null;
        if (!$dest) { cli_err("Uso: backup <dest.zip>"); exit(1); }
        if (!class_exists('ZipArchive')) { cli_err("ZipArchive mancante."); exit(1); }
        $zip = new ZipArchive();
        if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { cli_err("Impossibile aprire $dest"); exit(1); }
        $base = dirname(__DIR__);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/storage', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isDir()) continue;
            $local = substr($f->getPathname(), strlen($base) + 1);
            if (str_contains($local, '/logs/') || str_contains($local, '/tmp/')) continue;
            $zip->addFile($f->getPathname(), $local);
        }
        $envPath = $base . '/.env';
        if (is_file($envPath)) $zip->addFile($envPath, '.env');
        $zip->close();
        cli_out("Backup creato: $dest (include .env e storage/.key: trattalo come un segreto)");
        break;

    case 'process-jobs':
        Config::load();
        Db::init();
        $n = (int)($args[0] ?? 10);
        $done = JobService::process($n);
        cli_out("Processati $done job.");
        break;

    case 'vacuum':
        Config::load();
        Db::init();
        $pruned = \App\Services\ApiThrottle::prune();
        $sessions = \App\Core\Auth::pruneSessions();
        Db::delete('jobs', "status IN ('done','failed') AND finished_at < ?", [time() - 7 * 86400]);
        Db::delete('oauth_states', 'created_at < ?', [time() - 3600]);
        Db::delete('recovery_codes', 'created_at < ?', [time() - 7 * 86400]);
        $refresh = Db::delete('api_refresh_tokens', 'expires_at < ? OR (used_at IS NOT NULL AND used_at < ?)', [time() - 86400, time() - 7 * 86400]);
        $e2eOrphans = 0;
        foreach (Db::all('SELECT payload_path FROM e2e_shares') as $er) {
            $p = (string)Config::get('storage_path') . '/' . $er['payload_path'];
            if (!is_file($p)) $e2eOrphans++;
        }
        Db::delete('e2e_shares', 'revoked_at IS NOT NULL AND revoked_at < ?', [time() - 30 * 86400]);
        $tmpPurged = StorageService::purgeTemp(0);
        Db::pdo()->exec('VACUUM');
        $logFile = (string)Config::get('storage_path') . '/logs/app.log';
        if (is_file($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
            rename($logFile, $logFile . '.' . date('Ymd-His'));
            touch($logFile);
        }
        cli_out("Vacuum eseguito. rate_limits: -{$pruned}, sessions: -{$sessions}, refresh: -{$refresh}, e2e_orfani: {$e2eOrphans}, tmp: -{$tmpPurged}");
        break;

    case 'purge-trash':
        Config::load();
        StorageService::purgeTemp(0);
        Db::init();
        $days = isset($args[0]) ? max(1, (int)$args[0]) : 30;
        $threshold = time() - $days * 86400;
        $rows = Db::all('SELECT * FROM items WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$threshold]);
        $count = 0; $bytes = 0;
        $storage = (string)Config::get('storage_path');
        foreach ($rows as $r) {
            $refs = (int)Db::value('SELECT COUNT(*) FROM items WHERE sha256 = ? AND deleted_at IS NULL AND id != ?', [$r['sha256'], (int)$r['id']]);
            if ($refs === 0) {
                $abs = $storage . '/' . $r['storage_path'];
                if (is_file($abs)) { $bytes += filesize($abs); @unlink($abs); }
                if (!empty($r['thumbnail_path'])) { $ta = $storage . '/' . $r['thumbnail_path']; if (is_file($ta)) @unlink($ta); }
            }
            Db::delete('items', 'id = ?', [(int)$r['id']]);
            $count++;
        }
        cli_out("Cestino: $count righe eliminate, " . bytes_to_human($bytes) . " liberati (soglia: $days giorni).");
        break;

    case 'set':
        $envPath = dirname(__DIR__) . '/.env';
        if (count($args) < 2) { cli_err("Uso: set <KEY> <VALUE>"); exit(1); }
        \App\Controllers\SettingsController::updateEnv($envPath, [$args[0] => $args[1]]);
        cli_out("Aggiornato: {$args[0]}");
        break;

    default:
        cli_err("Comando sconosciuto: $cmd");
        exit(1);
}

function selfDecrypt(string $ct, string $ivB64, string $key): ?string
{
    $iv = base64_decode($ivB64, true);
    if ($iv === false) return null;
    return \App\Core\Crypto::decrypt($ct, $iv, $key);
}
