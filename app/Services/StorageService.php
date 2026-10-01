<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Logger;
use App\Models\Item;

final class StorageService
{
    public static function factory(string $backend, int $userId): ?BaseCloudClient
    {
        if ($backend === 'local' || $backend === '') return null;
        $client = match ($backend) {
            'google' => new GoogleDriveClient($userId),
            'dropbox' => new DropboxClient($userId),
            default => null,
        };
        if ($client === null) return null;
        try {
            $r = $client->accountInfo();
        } catch (\Throwable) {
            return null;
        }
        return ($r['ok'] ?? false) === true ? $client : null;
    }

    public static function put(string $tmpPath, string $originalName, int $userId, bool $encrypt, string $backend = 'local'): array
    {
        if ($backend !== 'local') {
            $client = self::factory($backend, $userId);
            if ($client === null) {
                Logger::warning('storage.cloud_fallback', ['backend' => $backend, 'user' => $userId, 'reason' => 'not_connected']);
            }
            if ($client !== null) {
                $size = (int)@filesize($tmpPath);
                $sha = hash_file('sha256', $tmpPath);
                try {
                    $res = $client->upload($tmpPath, Crypto::randomToken(16), (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath));
                    if (!empty($res['ok'])) {
                        return [
                            'path' => (string)($res['path'] ?? ('cloud/' . $backend . '/' . basename((string)$res['id']))),
                            'size' => $size,
                            'sha256' => $sha,
                            'encrypted' => false,
                            'is_encrypted' => 0,
                            'backend' => $backend,
                        ];
                    }
                } catch (\Throwable $e) {
                    Logger::warning('storage.cloud_upload_failed', ['backend' => $backend, 'err' => $e->getMessage()]);
                }
            }
        }
        $storage = (string)Config::get('storage_path');
        $dir = $storage . '/files/' . $userId;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $name = Crypto::randomToken(16);
        $ext = '';
        $dot = strrpos($originalName, '.');
        if ($dot !== false && $dot > 0 && $dot < strlen($originalName) - 1) {
            $ext = strtolower(substr($originalName, $dot + 1));
            if (!preg_match('/^[a-z0-9]{1,8}$/', $ext)) $ext = '';
        }
        $final = $name . ($ext ? '.' . $ext : '');
        $dest = $dir . '/' . $final;
        if ($encrypt) {
            $plain = file_get_contents($tmpPath);
            if ($plain === false) throw new \RuntimeException('cannot_read_tmp');
            $r = Crypto::encrypt($plain);
            file_put_contents($dest, $r['ct'] . '::IV::' . $r['iv']);
            $size = filesize($dest);
            $sha = hash('sha256', $plain);
        } else {
            if (!@rename($tmpPath, $dest)) {
                if (!@copy($tmpPath, $dest)) throw new \RuntimeException('cannot_write_file');
                @unlink($tmpPath);
            }
            $size = filesize($dest);
            $sha = hash_file('sha256', $dest);
        }
        @chmod($dest, 0640);
        return [
            'path' => 'files/' . $userId . '/' . $final,
            'size' => $size,
            'sha256' => $sha,
            'backend' => 'local',
            'encrypted' => $encrypt,
            'is_encrypted' => $encrypt ? 1 : 0,
        ];
    }

    public static function read(string $relPath, bool $encrypted): ?string
    {
        $abs = (string)Config::get('storage_path') . '/' . $relPath;
        if (!is_file($abs)) return null;
        $raw = @file_get_contents($abs);
        if ($raw === false) return null;
        if ($encrypted) {
            if (!str_contains($raw, '::IV::')) {
                Logger::error('storage.corrupt_encrypted', ['path' => $relPath]);
                return null;
            }
            [$ct, $iv] = explode('::IV::', $raw, 2);
            $dec = Crypto::decrypt($ct, $iv);
            if ($dec === null) {
                Logger::error('storage.decrypt_failed', ['path' => $relPath]);
            }
            return $dec;
        }
        return $raw;
    }

    public static function decryptToTemp(string $relPath, bool $encrypted): ?string
    {
        if (!$encrypted) return null;
        $plain = self::read($relPath, true);
        if ($plain === null) return null;
        $dir = (string)Config::get('storage_path') . '/tmp';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $tmp = $dir . '/' . bin2hex(random_bytes(16));
        if (@file_put_contents($tmp, $plain) === false) return null;
        @chmod($tmp, 0600);
        return $tmp;
    }

    public static function isEncryptedBlob(string $relPath): bool
    {
        $abs = (string)Config::get('storage_path') . '/' . $relPath;
        if (!is_file($abs)) return false;
        $fh = @fopen($abs, 'rb');
        if ($fh === false) return false;
        $size = (int)@filesize($abs);
        $marker = '::IV::';
        $probe = 4096;
        $tailLen = 128;
        if ($size > $probe) {
            fseek($fh, -$tailLen, SEEK_END);
            $tail = (string)fread($fh, $tailLen);
            if (str_contains($tail, $marker)) { fclose($fh); return true; }
        }
        rewind($fh);
        $buf = (string)fread($fh, $probe);
        fclose($fh);
        return str_contains($buf, $marker);
    }

    public static function purgeTemp(int $olderThanSec = 0): int
    {
        $dir = (string)Config::get('storage_path') . '/tmp';
        if (!is_dir($dir)) return 0;
        $n = 0;
        $cutoff = $olderThanSec > 0 ? time() - $olderThanSec : PHP_INT_MAX;
        foreach ((array)@scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || !is_file($dir . '/' . $f)) continue;
            $p = $dir . '/' . $f;
            if (@filemtime($p) > $cutoff) continue;
            if (@unlink($p)) $n++;
        }
        return $n;
    }

    public static function tmpBytes(): int
    {
        $dir = (string)Config::get('storage_path') . '/tmp';
        if (!is_dir($dir)) return 0;
        $n = 0;
        foreach ((array)@scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            if (is_file($p)) $n += (int)@filesize($p);
        }
        return $n;
    }

    public static function delete(string $relPath): void
    {
        $abs = (string)Config::get('storage_path') . '/' . $relPath;
        if (is_file($abs)) @unlink($abs);
    }

    public static function quotaUsed(int $userId): int
    {
        $r = \App\Core\Db::value(
            'SELECT COALESCE(SUM(live_size), 0) FROM (
                 SELECT MAX(size) AS live_size FROM items
                 WHERE user_id = ? AND deleted_at IS NULL AND sha256 IS NOT NULL AND sha256 <> ""
                 GROUP BY sha256
             )',
            [$userId]
        );
        $orphan = \App\Core\Db::value(
            'SELECT COALESCE(SUM(size), 0) FROM items WHERE user_id = ? AND deleted_at IS NULL AND (sha256 IS NULL OR sha256 = "")',
            [$userId]
        );
        $e2e = \App\Core\Db::value(
            'SELECT COALESCE(SUM(size), 0) FROM e2e_shares WHERE user_id = ? AND revoked_at IS NULL',
            [$userId]
        );
        return (int)$r + (int)$orphan + (int)$e2e + self::tmpBytes();
    }

    public static function userQuotaBytes(int $userId): int
    {
        $u = \App\Core\Db::first('SELECT storage_quota_mb FROM users WHERE id = ?', [$userId]);
        $mb = (int)($u['storage_quota_mb'] ?? 10240);
        return $mb * 1024 * 1024;
    }

    public static function checkQuota(int $userId, int $newBytes): bool
    {
        $used = self::quotaUsed($userId);
        return ($used + $newBytes) <= self::userQuotaBytes($userId);
    }
}
