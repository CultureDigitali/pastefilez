<?php
declare(strict_types=1);

namespace App\Core;

final class Audit
{
    public static function log(string $action, ?string $target = null, array $meta = []): void
    {
        try {
            Db::insert('audit_log', [
                'user_id' => Auth::id(),
                'action' => $action,
                'target' => $target,
                'ip' => Request::ip(),
                'ua' => Request::ua(),
                'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'created_at' => time(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Audit log failed: ' . $e->getMessage());
        }
    }
}
