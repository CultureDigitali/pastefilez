<?php
declare(strict_types=1);

namespace App\Core;

final class Installer
{
    public static function redirectToInstall(): void
    {
        $p = Request::path();
        if (str_starts_with($p, '/install')) return;
        Response::redirect('/install');
    }

    public static function isInstalled(): bool
    {
        return is_file((string)Config::get('master_key_path'));
    }

    public static function generateMasterKey(): string
    {
        $path = (string)Config::get('master_key_path');
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $key, LOCK_EX);
        chmod($tmp, 0600);
        rename($tmp, $path);
        chmod($path, 0600);
        return $path;
    }

    public static function createAdmin(string $email, string $password, string $displayName): int
    {
        Db::init();
        $exists = Db::first("SELECT id FROM users WHERE role IN ('owner','admin') LIMIT 1");
        if ($exists) throw new \RuntimeException('Admin already exists');
        $now = time();
        return Db::insert('users', [
            'email' => strtolower(trim($email)),
            'password_hash' => Crypto::hash($password),
            'role' => 'owner',
            'display_name' => $displayName,
            'language' => \App\Core\Config::get('default_language', 'it'),
            'theme' => 'dark',
            'storage_backend' => 'local',
            'storage_quota_mb' => (int)Config::get('quota_per_user_mb', 10240),
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
