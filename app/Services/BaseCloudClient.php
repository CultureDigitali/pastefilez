<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Logger;

abstract class BaseCloudClient
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;
    protected string $userId;

    public function __construct(int $userId)
    {
        $this->userId = (string)$userId;
        $cfg = $this->config();
        $this->clientId = $cfg['id'];
        $this->clientSecret = $cfg['secret'];
        $this->redirectUri = $cfg['redirect'];
    }

    abstract protected function config(): array;
    abstract public function name(): string;
    abstract public function authorizeUrl(string $state, string $codeVerifier): string;
    abstract public function exchangeCode(string $code, string $codeVerifier): array;
    abstract public function refresh(): array;
    abstract public function upload(string $localPath, string $remoteName, string $mime): array;
    abstract public function download(string $fileId, string $dest): bool;
    abstract public function listFiles(int $limit = 50): array;
    abstract public function accountInfo(): array;
    abstract public function deleteFile(string $fileId): bool;

    public function loadTokens(): ?array
    {
        $row = Db::first('SELECT * FROM cloud_tokens WHERE user_id = ? AND provider = ?', [$this->userId, $this->name()]);
        if (!$row) return null;
        $access = self::openBlob((string)$row['encrypted_access']);
        if ($access === null) return null;
        $refresh = null;
        if (!empty($row['encrypted_refresh'])) $refresh = self::openBlob((string)$row['encrypted_refresh']);
        return [
            'access' => $access,
            'refresh' => $refresh,
            'expires_at' => (int)$row['expires_at'],
            'account_email' => $row['account_email'],
        ];
    }

    public static function openBlob(string $blob): ?string
    {
        $plain = Crypto::decryptFromBase64($blob);
        if ($plain !== null) return $plain;
        $cut = strrpos($blob, ':');
        if ($cut === false || $cut === 0) return null;
        $iv = base64_decode(substr($blob, $cut + 1), true);
        if ($iv === false) return null;
        return Crypto::decrypt(substr($blob, 0, $cut), $iv);
    }

    public function saveTokens(string $access, ?string $refresh, ?int $expiresAt, ?string $email): void
    {
        $now = time();
        $accessBlob = Crypto::encryptToBase64($access);
        $accessIv = $this->ivFromBlob($accessBlob);
        $refreshBlob = ($refresh !== null && $refresh !== '') ? Crypto::encryptToBase64($refresh) : null;
        $refreshIv = $refreshBlob !== null ? $this->ivFromBlob($refreshBlob) : null;
        $vals = [
            'encrypted_access' => $accessBlob,
            'iv_access' => $accessIv,
            'encrypted_refresh' => $refreshBlob,
            'iv_refresh' => $refreshIv,
            'expires_at' => $expiresAt,
            'account_email' => $email,
            'updated_at' => $now,
        ];
        $exists = Db::first('SELECT id FROM cloud_tokens WHERE user_id = ? AND provider = ?', [$this->userId, $this->name()]);
        if ($exists) {
            Db::update('cloud_tokens', $vals, 'id = ?', [$exists['id']]);
            return;
        }
        Db::insert('cloud_tokens', array_merge($vals, [
            'user_id' => $this->userId,
            'provider' => $this->name(),
            'created_at' => $now,
        ]));
    }

    private function ivFromBlob(string $blob): string
    {
        $parts = explode(':', $blob, 2);
        return count($parts) === 2 ? $parts[1] : '';
    }

    public function disconnect(): void
    {
        Db::delete('cloud_tokens', 'user_id = ? AND provider = ?', [$this->userId, $this->name()]);
    }

    protected function http(string $method, string $url, array $headers = [], mixed $body = null, bool $bearer = true): array
    {
        $ch = curl_init();
        $h = $headers;
        if ($bearer) {
            $t = $this->loadTokens();
            if ($t && $t['access']) $h[] = 'Authorization: Bearer ' . $t['access'];
        }
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            if (is_array($body) && !isset($body['__binary'])) {
                $h[] = 'Content-Type: application/json';
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
            } else {
                if (isset($body['__binary'])) { $opts[CURLOPT_POSTFIELDS] = $body['__binary']; unset($body['__binary']); }
            }
        }
        if ($h) $opts[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['ok' => false, 'status' => 0, 'error' => $err];
        $json = json_decode($resp, true);
        return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'data' => $json, 'raw' => $resp];
    }
}
