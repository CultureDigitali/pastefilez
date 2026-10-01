<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Logger;

final class JobService
{
    public static function enqueue(string $type, int $userId, array $payload, int $delaySec = 0): int
    {
        return Db::insert('jobs', [
            'user_id' => $userId,
            'type' => $type,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'scheduled_at' => time() + $delaySec,
        ]);
    }

    public static function process(int $limit = 3): int
    {
        $processed = 0;
        $processed = 0;
        for ($i = 0; $i < (int)$limit; $i++) {
            $claimed = Db::first(
                "UPDATE jobs SET status = 'processing', started_at = ?, attempts = attempts + 1
                 WHERE id = (SELECT id FROM jobs WHERE status = 'pending' AND scheduled_at <= ? ORDER BY id ASC LIMIT 1)
                 RETURNING *",
                [time(), time()]
            );
            if (!$claimed) break;
            $r = $claimed;
            try {
                $payload = json_decode($r['payload'] ?? '[]', true) ?: [];
                self::run($r['type'], (int)$r['user_id'], $payload, (int)$r['id']);
                Db::update('jobs', ['status' => 'done', 'finished_at' => time()], 'id = ?', [$r['id']]);
            } catch (\Throwable $e) {
                Logger::error('job.fail', ['id' => $r['id'], 'type' => $r['type'], 'err' => $e->getMessage()]);
                $attempts = (int)$r['attempts'];
                $status = $attempts >= 4 ? 'failed' : 'pending';
                $delay = (int)pow(2, $attempts) * 30;
                Db::update('jobs', [
                    'status' => $status,
                    'last_error' => $e->getMessage(),
                    'finished_at' => $status === 'failed' ? time() : null,
                    'scheduled_at' => $status === 'pending' ? time() + $delay : time(),
                ], 'id = ?', [$r['id']]);
                if ($status === 'failed' && $r['type'] === 'analyze' && !empty($payload['item_id'])) {
                    Db::update('items', ['ai_status' => 'failed', 'ai_error' => $e->getMessage()], 'id = ? AND user_id = ?', [(int)$payload['item_id'], (int)$r['user_id']]);
                }
            }
            $processed++;
        }
        return $processed;
    }

    public static function run(string $type, int $userId, array $payload, int $jobId): void
    {
        switch ($type) {
            case 'analyze':
                (new \App\Services\AnalysisService())->run($userId, (int)$payload['item_id']);
                break;
            case 'embedding':
                (new \App\Services\AnalysisService())->embed($userId, (int)$payload['item_id']);
                break;
            case 'webhook':
                WebhookService::deliver($userId, (string)($payload['event'] ?? ''), (array)($payload['payload'] ?? []));
                break;
        }
    }
}
