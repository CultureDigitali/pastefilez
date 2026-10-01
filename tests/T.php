<?php
declare(strict_types=1);

namespace Tests;

final class T
{
    private static int $pass = 0;
    private static array $fail = [];
    private static string $group = '';

    public static function group(string $g): void
    {
        self::$group = $g;
    }

    public static function ok(bool $cond, string $name, string $detail = ''): void
    {
        if ($cond) {
            self::$pass++;
            return;
        }
        self::$fail[] = self::$group . ' :: ' . $name . ($detail !== '' ? ' -- ' . $detail : '');
    }

    public static function eq(mixed $expected, mixed $actual, string $name): void
    {
        self::ok($expected === $actual, $name, 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }

    public static function contains(string $haystack, string $needle, string $name): void
    {
        self::ok(str_contains($haystack, $needle), $name, 'missing: ' . $needle);
    }

    public static function notContains(string $haystack, string $needle, string $name): void
    {
        self::ok(!str_contains($haystack, $needle), $name, 'unexpectedly present: ' . $needle);
    }

    public static function throws(callable $fn, string $name): void
    {
        try {
            $fn();
            self::ok(false, $name, 'no exception thrown');
        } catch (\Throwable) {
            self::ok(true, $name);
        }
    }

    public static function report(): int
    {
        $total = self::$pass + count(self::$fail);
        echo "\n";
        if (self::$fail) {
            echo "FAILURES (" . count(self::$fail) . "):\n";
            foreach (self::$fail as $f) echo "  - $f\n";
        }
        printf("\n%d/%d passed%s\n", self::$pass, $total, self::$fail ? '' : '  ALL GREEN');
        return self::$fail ? 1 : 0;
    }
}
