<?php
declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    public static function hit(string $key, int $maxPerHour = 0): bool
    {
        $k = self::bucketKey($key);
        $window = (int)floor(time() / 3600);
        Db::run("INSERT INTO rate_limits(key, window_start, count) VALUES(?, ?, 1)
                  ON CONFLICT(key, window_start) DO UPDATE SET count = count + 1", [$k, $window]);
        return self::withinLimit($k, $window, $maxPerHour);
    }

    public static function check(string $key, int $maxPerHour = 0): bool
    {
        $k = self::bucketKey($key);
        $window = (int)floor(time() / 3600);
        $count = (int)Db::value('SELECT count FROM rate_limits WHERE key = ? AND window_start = ?', [$k, $window]);
        return $count < self::maxFor($maxPerHour);
    }

    public static function remaining(string $key, int $maxPerHour = 0): int
    {
        $k = self::bucketKey($key);
        $window = (int)floor(time() / 3600);
        $count = (int)Db::value('SELECT count FROM rate_limits WHERE key = ? AND window_start = ?', [$k, $window]);
        return max(0, self::maxFor($maxPerHour) - $count);
    }

    private static function withinLimit(string $k, int $window, int $maxPerHour): bool
    {
        $count = (int)Db::value('SELECT count FROM rate_limits WHERE key = ? AND window_start = ?', [$k, $window]);
        return $count <= self::maxFor($maxPerHour);
    }

    private static function maxFor(int $maxPerHour): int
    {
        return $maxPerHour > 0 ? $maxPerHour : (int)Config::get('rate_login_hour', 10);
    }

    private static function bucketKey(string $key): string
    {
        $key = preg_replace('/[^a-zA-Z0-9_.:@-]/', '_', $key) ?? '';
        return substr($key, 0, 180);
    }
}
