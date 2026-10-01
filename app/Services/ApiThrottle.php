<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;

final class ApiThrottle
{
    public const TIERS = [
        'read' => 'rate_api_read_hour',
        'write' => 'rate_api_write_hour',
        'ai' => 'rate_api_ai_hour',
        'auth' => 'rate_api_auth_hour',
        'search' => 'rate_api_search_hour',
    ];

    public const TIER_DEFAULTS = [
        'read' => 600,
        'write' => 120,
        'ai' => 20,
        'auth' => 30,
        'search' => 120,
    ];

    public const TIER_IP_MULTIPLIER = [
        'read' => 4,
        'write' => 4,
        'ai' => 2,
        'auth' => 4,
        'search' => 2,
    ];

    public const AI_WEB_PATHS = ['/chat/ask', '/settings/ai/test', '/settings/ai/refresh-models', '/items/bulk'];

    public static function tierFor(string $method, string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if (str_starts_with($path, '/api/v1/auth/')) return 'auth';
        if (str_contains($path, '/ai/')) return 'ai';
        foreach (self::AI_WEB_PATHS as $p) {
            if (str_starts_with($path, $p)) return 'ai';
        }
        if (str_contains($path, '/search')) return 'search';
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return 'write';
        return 'read';
    }

    public static function shouldThrottle(string $path): bool
    {
        $p = '/' . ltrim($path, '/');
        if (str_starts_with($p, '/api/v1/')) return true;
        $tier = self::tierFor('POST', $p);
        return in_array($tier, ['ai', 'search'], true);
    }

    public static function limitFor(string $tier): int
    {
        $key = self::TIERS[$tier] ?? null;
        if ($key === null) return self::TIER_DEFAULTS['read'];
        $v = (int)Config::get($key, 0);
        return $v > 0 ? $v : (self::TIER_DEFAULTS[$tier] ?? 600);
    }

    public static function ipLimitFor(string $tier): int
    {
        $mult = self::TIER_IP_MULTIPLIER[$tier] ?? 4;
        return max(self::limitFor($tier), self::limitFor($tier) * $mult);
    }

    public static function validatedIdentity(): ?int
    {
        $u = ApiAuth::user(true);
        return $u === null ? null : (int)$u['id'];
    }

    public static function ipBucket(string $tier): string
    {
        return 'api:' . $tier . ':ip:' . Request::ip();
    }

    public static function identityBucket(string $tier, ?int $userId): string
    {
        return 'api:' . $tier . ':u:' . ($userId ?? 0);
    }

    public static function enforce(string $method, string $path): void
    {
        $tier = self::tierFor($method, $path);
        $userId = self::validatedIdentity();

        $idLimit = $userId === null ? self::ipLimitFor($tier) : self::limitFor($tier);
        $idKey = $userId === null ? self::ipBucket($tier) : self::identityBucket($tier, $userId);

        if (!RateLimiter::hit($idKey, $idLimit)) self::deny($tier, $idLimit);

        if ($userId !== null) {
            $ipLimit = self::ipLimitFor($tier);
            if (!RateLimiter::hit(self::ipBucket($tier), $ipLimit)) self::deny($tier, $ipLimit);
        }
    }

    private static function deny(string $tier, int $limit): void
    {
        $retry = self::retryAfterSeconds();
        Response::json(
            ['error' => 'rate_limited', 'tier' => $tier, 'limit' => $limit, 'retry_after' => $retry],
            429,
            ['Retry-After' => (string)$retry]
        );
    }

    public static function retryAfterSeconds(): int
    {
        return max(1, 3600 - (time() % 3600));
    }

    public static function prune(): int
    {
        $cutoff = (int)floor(time() / 3600) - 1;
        return Db::delete('rate_limits', 'window_start < ?', [$cutoff]);
    }
}
