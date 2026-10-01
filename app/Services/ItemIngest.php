<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Db;
use App\Core\Logger;

final class ItemIngest
{
    public static function fromUpload(array $user, array $f, string $prefix = '', bool $dispatchWebhook = true): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return self::err('unauthenticated', 401);

        $tmp = (string)($f['tmp_name'] ?? '');
        $rawName = $f['name'] ?? 'file';
        if (!is_string($rawName)) $rawName = 'file';
        $name = UploadGuard::safeName($prefix . $rawName);
        $size = self::sizeOf($tmp, $f);

        $max = (int)Config::get('max_upload_mb', 10240) * 1024 * 1024;
        if ($size <= 0 || $size > $max) {
            self::discard($tmp);
            return self::err(t('upload.too_big', ['size' => bytes_to_human($size)]), 413);
        }
        if (!is_file($tmp)) {
            return self::err('missing_temp_file', 400);
        }

        $mime = UploadGuard::detectMime($tmp);
        if (!UploadGuard::mimeAllowed($mime)) {
            self::discard($tmp);
            return self::err(t('upload.mime_blocked', ['mime' => $mime !== '' ? $mime : 'unknown']), 415);
        }

        $encrypt = (bool)Config::get('encrypt_files');

        $stored = null;
        $duplicateOf = null;
        $preSha = @hash_file('sha256', $tmp);
        if (is_string($preSha) && $preSha !== '') {
            $existing = Db::first(
                'SELECT id, storage_path, is_encrypted, size FROM items WHERE user_id = ? AND sha256 = ? AND deleted_at IS NULL ORDER BY id ASC LIMIT 1',
                [$userId, $preSha]
            );
            if ($existing && (int)$existing['is_encrypted'] === ($encrypt ? 1 : 0)) {
                $stored = ['path' => (string)$existing['storage_path'], 'size' => (int)$existing['size'], 'sha256' => $preSha];
                $duplicateOf = (int)$existing['id'];
                self::discard($tmp);
            }
        }
        $backend = (string)($user['storage_backend'] ?? 'local');
        if ($stored === null) {
            if (!StorageService::checkQuota($userId, $size)) {
                self::discard($tmp);
                return self::err(t('upload.quota_exceeded'), 413);
            }
            try {
                $stored = StorageService::put($tmp, $name, $userId, $encrypt, $backend);
            } catch (\Throwable $e) {
                self::discard($tmp);
                Logger::error('ingest.store_failed', ['err' => $e->getMessage()]);
                return self::err('store_failed', 500);
            }
        }

        $thumbPath = null;
        $w = null;
        $h = null;
        if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
            $isEnc = (int)($stored['is_encrypted'] ?? 0) === 1
                || StorageService::isEncryptedBlob((string)$stored['path']);
            $srcAbs = $isEnc
                ? (StorageService::decryptToTemp((string)$stored['path'], true) ?? '')
                : ((string)Config::get('storage_path') . '/' . $stored['path']);
            if ($srcAbs !== '' && is_file($srcAbs)) {
                $thumbPath = ThumbnailService::make($srcAbs, $mime, bin2hex(random_bytes(8)));
                if ($thumbPath && str_starts_with($mime, 'image/')) {
                    $info = @getimagesize($srcAbs);
                    if ($info) { $w = (int)$info[0]; $h = (int)$info[1]; }
                }
                if ($isEnc && $srcAbs !== '') @unlink($srcAbs);
            }
        }

        $now = time();
        $id = Db::insert('items', [
            'user_id' => $userId,
            'folder_id' => null,
            'type' => UploadGuard::kindFromMime($mime),
            'mime' => $mime,
            'original_name' => $name,
            'storage_backend' => (string)($stored['backend'] ?? 'local'),
            'storage_path' => $stored['path'],
            'storage_id' => null,
            'size' => $stored['size'],
            'sha256' => $stored['sha256'],
            'is_encrypted' => (!empty($stored['encrypted']) || ($encrypt && ($stored['backend'] ?? 'local') === 'local')) ? 1 : 0,
            'thumbnail_path' => $thumbPath,
            'width' => $w,
            'height' => $h,
            'ai_status' => 'pending',
            'is_public' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        JobService::enqueue('analyze', $userId, ['item_id' => $id], 0);
        Audit::log('item.upload', 'item:' . $id, ['mime' => $mime, 'size' => $stored['size'], 'dup' => $duplicateOf]);
        if ($dispatchWebhook) {
            WebhookService::dispatch($userId, 'item.uploaded', [
                'id' => $id, 'name' => $name, 'mime' => $mime, 'size' => (int)$stored['size'],
            ]);
        }

        $out = ['ok' => true, 'id' => (int)$id, 'name' => $name, 'mime' => $mime, 'size' => (int)$stored['size']];
        if ($duplicateOf) $out['duplicate_of'] = $duplicateOf;
        return $out;
    }

    private static function sizeOf(string $tmp, array $f): int
    {
        if (is_file($tmp)) {
            $s = @filesize($tmp);
            if (is_int($s) && $s > 0) return $s;
        }
        return (int)($f['size'] ?? 0);
    }

    private static function discard(string $tmp): void
    {
        if ($tmp === '' || !is_file($tmp)) return;
        $storage = realpath((string)Config::get('storage_path') . '/files');
        $target = realpath($tmp);
        if ($storage !== false && $target !== false && str_starts_with($target, $storage . '/')) return;
        @unlink($tmp);
    }

    private static function err(string $msg, int $status): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $msg];
    }
}
