<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Logger;

final class WebhookService
{
    public static function outgoingCreate(int $userId, string $name, string $targetUrl, array $events, ?string $secret): int
    {
        $token = Crypto::randomToken(24);
        return Db::insert('webhooks', [
            'user_id' => $userId,
            'direction' => 'out',
            'name' => $name,
            'token' => $token,
            'target_url' => $targetUrl,
            'events' => json_encode($events, JSON_UNESCAPED_UNICODE),
            'secret' => self::sealSecret($secret),
            'is_active' => 1,
            'created_at' => time(),
        ]);
    }

    public static function incomingCreate(int $userId, string $name): array
    {
        $token = Crypto::randomToken(28);
        $id = Db::insert('webhooks', [
            'user_id' => $userId,
            'direction' => 'in',
            'name' => $name,
            'token' => $token,
            'target_url' => null,
            'events' => null,
            'secret' => null,
            'is_active' => 1,
            'created_at' => time(),
        ]);
        return ['id' => $id, 'token' => $token];
    }

    public static function delete(int $id, int $userId): bool
    {
        return Db::delete('webhooks', 'id = ? AND user_id = ?', [$id, $userId]) > 0;
    }

    public static function list(int $userId): array
    {
        return Db::all('SELECT * FROM webhooks WHERE user_id = ? ORDER BY id DESC', [$userId]);
    }

    public static function findIncoming(string $token): ?array
    {
        $r = Db::first('SELECT * FROM webhooks WHERE token = ? AND direction = ? AND is_active = 1', [$token, 'in']);
        return $r ?: null;
    }

    public static function dispatch(int $userId, string $event, array $payload): void
    {
        $rows = Db::all('SELECT * FROM webhooks WHERE user_id = ? AND direction = ? AND is_active = ?', [$userId, 'out', 1]);
        if (!$rows) return;
        JobService::enqueue('webhook', $userId, ['event' => $event, 'payload' => $payload], 2);
    }

    public static function deliver(int $userId, string $event, array $payload): void
    {
        $rows = Db::all('SELECT * FROM webhooks WHERE user_id = ? AND direction = ? AND is_active = ?', [$userId, 'out', 1]);
        foreach ($rows as $w) {
            $events = json_decode((string)$w['events'], true);
            if (!is_array($events)) continue;
            if (!in_array($event, $events, true) && !in_array('*', $events, true)) continue;
            self::send($w, $event, $payload);
        }
    }

    private static function send(array $w, string $event, array $payload): void
    {
        $url = (string)($w['target_url'] ?? '');
        if ($url === '') return;
        $body = json_encode(['event' => $event, 'data' => $payload, 'ts' => time()], JSON_UNESCAPED_UNICODE);
        $secret = self::openSecret($w['secret'] ?? null);
        $sig = $secret !== null ? hash_hmac('sha256', $body, $secret) : null;
        $headers = ['Content-Type' => 'application/json', 'X-PZ-Event' => $event];
        if ($sig) $headers['X-PZ-Signature'] = 'sha256=' . $sig;
        try {
            HttpClient::assertSafeUrl($url);
            $resp = HttpClient::request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => 5,
                'max_bytes' => 8192,
            ]);
            $status = $resp['status'];
            $stored = mb_substr($resp['error'] !== '' ? 'curl_error: ' . $resp['error'] : $resp['body'], 0, 500);
        } catch (\RuntimeException $e) {
            $status = 0;
            $stored = 'blocked: ' . $e->getMessage();
        }
        Db::insert('webhook_deliveries', [
            'webhook_id' => (int)$w['id'],
            'event' => $event,
            'payload' => mb_substr($body, 0, 2000),
            'status' => $status,
            'response_body' => $stored,
            'created_at' => time(),
        ]);
        Db::update('webhooks', ['last_triggered_at' => time(), 'last_status' => $status], 'id = ?', [(int)$w['id']]);
        Logger::info('webhook.sent', ['id' => (int)$w['id'], 'event' => $event, 'status' => $status]);
    }

    public static function sealSecret(?string $secret): ?string
    {
        if ($secret === null || $secret === '') return null;
        return Crypto::encryptToBase64($secret);
    }

    public static function openSecret(?string $stored): ?string
    {
        if ($stored === null || $stored === '') return null;
        $plain = Crypto::decryptFromBase64($stored);
        if ($plain !== null) return $plain;
        return $stored;
    }
}
