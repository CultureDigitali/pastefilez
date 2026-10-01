<?php
declare(strict_types=1);

namespace App\Core;

final class Crypto
{
    public static function masterKey(): string
    {
        $path = (string)Config::get('master_key_path');
        if (!is_file($path)) Installer::redirectToInstall();
        $k = file_get_contents($path);
        if ($k === false || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid master key');
        }
        return $k;
    }

    public static function encrypt(string $plain, ?string $key = null): array
    {
        $key = $key ?? self::masterKey();
        $iv = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct = sodium_crypto_secretbox($plain, $iv, $key);
        return ['ct' => $ct, 'iv' => $iv];
    }

    public static function decrypt(string $cipher, string $iv, ?string $key = null): ?string
    {
        $key = $key ?? self::masterKey();
        try {
            $pt = sodium_crypto_secretbox_open($cipher, $iv, $key);
            return $pt === false ? null : $pt;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function encryptToBase64(string $plain, ?string $key = null): string
    {
        $r = self::encrypt($plain, $key);
        return base64_encode($r['ct']) . ':' . base64_encode($r['iv']);
    }

    public static function decryptFromBase64(string $blob, ?string $key = null): ?string
    {
        $parts = explode(':', $blob, 2);
        if (count($parts) !== 2) return null;
        $ct = base64_decode($parts[0], true);
        $iv = base64_decode($parts[1], true);
        if ($ct === false || $iv === false) return null;
        return self::decrypt($ct, $iv, $key);
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 1,
        ]);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1,
        ]);
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hmac(string $data, ?string $key = null): string
    {
        return hash_hmac('sha256', $data, $key ?? self::masterKey());
    }

    public static function constantTimeEquals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
