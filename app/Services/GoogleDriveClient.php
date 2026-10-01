<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Logger;

final class GoogleDriveClient extends BaseCloudClient
{
    public function name(): string { return 'google'; }

    protected function config(): array
    {
        $c = (array)Config::get('google');
        return [
            'id' => $c['client_id'] ?? '',
            'secret' => $c['client_secret'] ?? '',
            'redirect' => $c['redirect'] ?: (rtrim((string)Config::get('app_url'), '/') . '/settings/cloud/google/callback'),
        ];
    }

    public function authorizeUrl(string $state, string $codeVerifier): string
    {
        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $r = $this->http('POST', 'https://oauth2.googleapis.com/token', [], [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
            'code_verifier' => $codeVerifier,
        ], false);
        if (!$r['ok']) return $r;
        $this->saveTokens($r['data']['access_token'], $r['data']['refresh_token'] ?? null, time() + (int)($r['data']['expires_in'] ?? 3600), null);
        $info = $this->http('GET', 'https://www.googleapis.com/oauth2/v2/userinfo');
        $email = $info['data']['email'] ?? null;
        if ($email) {
            $this->saveTokens($r['data']['access_token'], $r['data']['refresh_token'] ?? null, time() + (int)($r['data']['expires_in'] ?? 3600), $email);
        }
        return ['ok' => true];
    }

    public function refresh(): array
    {
        $t = $this->loadTokens();
        if (!$t || !$t['refresh']) return ['ok' => false, 'error' => 'no_refresh'];
        $r = $this->http('POST', 'https://oauth2.googleapis.com/token', [], [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $t['refresh'],
            'grant_type' => 'refresh_token',
        ], false);
        if (!$r['ok']) return $r;
        $this->saveTokens($r['data']['access_token'], $t['refresh'], time() + (int)($r['data']['expires_in'] ?? 3600), $t['account_email']);
        return ['ok' => true];
    }

    public function upload(string $localPath, string $remoteName, string $mime): array
    {
        if (!$this->ensureFreshToken()) return ['ok' => false, 'error' => 'auth'];
        $meta = json_encode(['name' => $remoteName]);
        $boundary = '----PZ' . bin2hex(random_bytes(8));
        $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n--$boundary\r\nContent-Type: $mime\r\n\r\n" . file_get_contents($localPath) . "\r\n--$boundary--";
        $r = $this->http('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart', [
            'Content-Type: multipart/related; boundary=' . $boundary,
        ], $body);
        return $r;
    }

    public function download(string $fileId, string $dest): bool
    {
        if (!$this->ensureFreshToken()) return false;
        $url = "https://www.googleapis.com/drive/v3/files/$fileId?alt=media";
        $ch = curl_init($url);
        $t = $this->loadTokens();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $t['access']],
            CURLOPT_FILE => fopen($dest, 'w'),
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    public function listFiles(int $limit = 50): array
    {
        if (!$this->ensureFreshToken()) return [];
        $r = $this->http('GET', "https://www.googleapis.com/drive/v3/files?pageSize=$limit&fields=files(id,name,mimeModifierInfo,size,webContentLink,thumbnailLink,createdTime)");
        return $r['data']['files'] ?? [];
    }

    public function accountInfo(): array
    {
        if (!$this->ensureFreshToken()) return [];
        $r = $this->http('GET', 'https://www.googleapis.com/oauth2/v2/userinfo');
        return $r['data'] ?? [];
    }

    public function deleteFile(string $fileId): bool
    {
        if (!$this->ensureFreshToken()) return false;
        $r = $this->http('DELETE', "https://www.googleapis.com/drive/v3/files/$fileId");
        return $r['ok'];
    }

    private function ensureFreshToken(): bool
    {
        $t = $this->loadTokens();
        if (!$t || !$t['access']) return false;
        if ($t['expires_at'] && $t['expires_at'] - 60 < time()) {
            $r = $this->refresh();
            return $r['ok'];
        }
        return true;
    }
}
