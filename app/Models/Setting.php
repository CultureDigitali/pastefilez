<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Setting
{
    public static function get(int $userId, string $key, mixed $default = null): mixed
    {
        $row = Db::first('SELECT value FROM settings WHERE user_id = ? AND key = ?', [$userId, $key]);
        if (!$row) return $default;
        return self::unserialize($row['value']);
    }

    public static function set(int $userId, string $key, mixed $value): void
    {
        $v = self::serialize($value);
        $exists = Db::first('SELECT 1 FROM settings WHERE user_id = ? AND key = ?', [$userId, $key]);
        if ($exists) {
            Db::update('settings', ['value' => $v], 'user_id = ? AND key = ?', [$userId, $key]);
        } else {
            Db::insert('settings', ['user_id' => $userId, 'key' => $key, 'value' => $v]);
        }
    }

    public static function del(int $userId, string $key): void
    {
        Db::delete('settings', 'user_id = ? AND key = ?', [$userId, $key]);
    }

    private static function serialize(mixed $v): string
    {
        if (is_bool($v)) return $v ? '1' : '0';
        if ($v === null) return '';
        if (is_scalar($v)) return (string)$v;
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function unserialize(string $v): mixed
    {
        if ($v === '') return null;
        if ($v === '1' || $v === '0') return $v === '1';
        if ($v[0] === '[' || $v[0] === '{') {
            $j = json_decode($v, true);
            return $j === null ? $v : $j;
        }
        return $v;
    }
}
