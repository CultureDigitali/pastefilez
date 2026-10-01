<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

final class DropboxClient extends BaseCloudClient
{
    public function name(): string { return 'dropbox'; }

    protected function config(): array
    {
        $c = (array)Config::get('dropbox');
        return [
            'id' => $c['client_id'] ?? '',
            'secret' => $c['client_secret'] ?? '',
            'redirect' => $c['redirect'] ?: (rtrim((string)Config::get('app_url'), '/') . '/settings/cloud/dropbox/callback'),
        ];
    }

    public function authorizeUrl(string $state, string $codeVerifier): string
    {
        $params = http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'scope' => 'files.content.write files.content.read account_info.read',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'token_access_type' => 'offline',
        ]);
        return 'https://www.dropbox.com/oauth2/authorize?' . $params;
    }

    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $r = $this->http('POST', 'https://api.dropbox.com/oauth2/token', [], [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ], false);
        if (!$r['ok']) return $r;
        $this->saveTokens($r['data']['access_token'], $r['data']['refresh_token'] ?? null, time() + (int)($r['data']['expires_in'] ?? 14400), null);
        $acc = $this->http('POST', 'https://api.dropboxapi.com/2/users/get_current_account', []);
        $email = $acc['data']['email'] ?? null;
        if ($email) {
            $this->saveTokens($r['data']['access_token'], $r['data']['refresh_token'] ?? null, time() + (int)($r['data']['expires_in'] ?? 14400), $email);
        }
        return ['ok' => true];
    }

    public function refresh(): array
    {
        $t = $this->loadTokens();
        if (!$t || !$t['refresh']) return ['ok' => false, 'error' => 'no_refresh'];
        $r = $this->http('POST', 'https://api.dropbox.com/oauth2/token', [], [
            'grant_type' => 'refresh_token',
            'refresh_token' => $t['refresh'],
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ], false);
        if (!$r['ok']) return $r;
        $this->saveTokens($r['data']['access_token'], $t['refresh'], time() + (int)($r['data']['expires_in'] ?? 14400), $t['account_email']);
        return ['ok' => true];
    }

    public function upload(string $localPath, string $remoteName, string $mime): array
    {
        if (!$this->ensureFreshToken()) return ['ok' => false, 'error' => 'auth'];
        $args = json_encode(['path' => '/' . $remoteName, 'mode' => 'overwrite', 'autorename' => true, 'mute' => false]);
        $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
        $t = $this->loadTokens();
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $t['access'],
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: ' . $args,
            ],
            CURLOPT_POSTFIELDS => file_get_contents($localPath),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => $code < 300, 'status' => $code, 'data' => json_decode($resp, true)];
    }

    public function download(string $fileId, string $dest): bool
    {
        if (!$this->ensureFreshToken()) return false;
        $ch = curl_init('https://content.dropboxapi.com/2/files/download');
        $t = $this->loadTokens();
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $t['access'],
                'Dropbox-API-Arg: ' . json_encode(['path' => $fileId]),
            ],
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
        $r = $this->http('POST', 'https://api.dropboxapi.com/2/files/list_folder', ['Content-Type: application/json'], ['path' => '', 'limit' => $limit]);
        return $r['data']['entries'] ?? [];
    }

    public function accountInfo(): array
    {
        if (!$this->ensureFreshToken()) return [];
        $r = $this->http('POST', 'https://api.dropboxapi.com/2/users/get_current_account', ['Content-Type: application/json'], null);
        return $r['data'] ?? [];
    }

    public function deleteFile(string $fileId): bool
    {
        if (!$this->ensureFreshToken()) return false;
        $r = $this->http('POST', 'https://api.dropboxapi.com/2/files/delete_v2', ['Content-Type: application/json'], ['path' => $fileId]);
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
