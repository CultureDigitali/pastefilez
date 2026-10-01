<?php
declare(strict_types=1);

namespace App\Core;

final class I18n
{
    private static array $catalog = [];
    private static string $lang = 'it';

    public static function init(): void
    {
        $user = Auth::user();
        $lang = $user['language'] ?? Session::get('lang');
        $lang = $lang ?: (string)Config::get('default_lang', 'it');
        if (!in_array($lang, ['it', 'en'], true)) $lang = 'it';
        self::$lang = $lang;
        self::load($lang);
    }

    public static function setLang(string $lang): void
    {
        if (!in_array($lang, ['it', 'en'], true)) return;
        self::$lang = $lang;
        Session::set('lang', $lang);
        $id = Auth::id();
        if ($id) Db::update('users', ['language' => $lang, 'updated_at' => time()], 'id = ?', [$id]);
        self::load($lang);
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    public static function t(string $key, array $vars = []): string
    {
        $v = self::$catalog[$key] ?? $key;
        if ($vars) {
            foreach ($vars as $k => $val) {
                $v = str_replace('{' . $k . '}', (string)$val, $v);
            }
        }
        return $v;
    }

    private static function load(string $lang): void
    {
        $path = dirname(__DIR__, 2) . '/lang/' . $lang . '.json';
        if (is_file($path)) {
            $data = json_decode((string)file_get_contents($path), true);
            self::$catalog = is_array($data) ? $data : [];
        } else {
            self::$catalog = [];
        }
    }
}
