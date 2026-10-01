<?php
declare(strict_types=1);

use Tests\T;

T::group('settings-env-injection');

function parseEnv(string $content): array
{
    $out = [];
    foreach (explode("\n", $content) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $out[$k] = $v;
    }
    return $out;
}

$dir = sys_get_temp_dir() . '/pf_env_' . getmypid();
@mkdir($dir, 0750, true);
$envPath = $dir . '/.env';
file_put_contents($envPath, "APP_NAME=PasteFilez\nAPP_DEBUG=false\nSMTP_HOST=old.example\n");

$before = parseEnv((string)file_get_contents($envPath));
T::eq(3, count($before), 'baseline key count');

$payload = "x\nAPP_DEBUG=true\nDB_PATH=/tmp/pwn.sqlite";
\App\Controllers\SettingsController::updateEnv($envPath, ['SMTP_FROM_NAME' => $payload]);
$content = (string)file_get_contents($envPath);
$parsed = parseEnv($content);

T::eq(4, count($parsed), 'only the intended key is added by newline injection: ' . implode(',', array_keys($parsed)));
T::eq('false', $parsed['APP_DEBUG'] ?? null, 'APP_DEBUG cannot be overridden by injection');
T::ok(!isset($parsed['DB_PATH']), 'DB_PATH cannot be injected');
T::eq('old.example', $parsed['SMTP_HOST'] ?? null, 'unrelated key untouched');
T::ok(!str_contains($content, "\r"), 'no CR written');
T::eq(1, substr_count($content, 'SMTP_FROM_NAME='), 'injected value written exactly once');

$parsed = parseEnv((string)file_get_contents($envPath));
T::ok(!str_contains((string)($parsed['SMTP_FROM_NAME'] ?? ''), "\n"), 'stored value is single-line');
T::ok(str_contains((string)($parsed['SMTP_FROM_NAME'] ?? ''), 'APP_DEBUG=true'), 'payload is inert text, not a directive');

\App\Controllers\SettingsController::updateEnv($envPath, ['SMTP_HOST' => "a\r\nEVIL=1"]);
$parsed = parseEnv((string)file_get_contents($envPath));
T::eq(4, count($parsed), 'CRLF injection creates no key');
T::ok(!isset($parsed['EVIL']), 'EVIL not created via CRLF');

\App\Controllers\SettingsController::updateEnv($envPath, ['SMTP_HOST' => 'good.example']);
$content3 = (string)file_get_contents($envPath);
$parsed = parseEnv($content3);
T::eq('good.example', $parsed['SMTP_HOST'] ?? null, 'normal value written');
T::eq(4, count($parsed), 'key not duplicated');

\App\Controllers\SettingsController::updateEnv($envPath, ['SMTP_PASS' => 'p"a\\ss wörd']);
$parsed = parseEnv((string)file_get_contents($envPath));
T::eq(5, count($parsed), 'password adds exactly one key');
T::ok(str_contains((string)($parsed['SMTP_PASS'] ?? ''), 'p'), 'password value persisted');
T::ok(str_starts_with((string)($parsed['SMTP_PASS'] ?? ''), '"'), 'value with quotes is wrapped for safe parsing');
T::eq(1, substr_count((string)file_get_contents($envPath), 'SMTP_PASS='), 'password key appears once');

\App\Controllers\SettingsController::updateEnv($envPath, ['BAD-KEY' => 'x', 'lowercase' => 'y']);
$parsed = parseEnv((string)file_get_contents($envPath));
T::eq(5, count($parsed), 'invalid env key names are rejected');

@unlink($envPath);
@rmdir($dir);
