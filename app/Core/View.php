<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = []): string
    {
        $path = dirname(__DIR__) . '/Views/' . $template . '.php';
        if (!is_file($path)) return '<!-- view missing: ' . htmlspecialchars($template) . ' -->';
        $data = array_merge(self::$shared, $data);
        $data['__view'] = $template;
        extract($data, EXTR_SKIP);
        ob_start();
        include $path;
        return (string)ob_get_clean();
    }

    public static function layout(string $content, array $data = []): string
    {
        $data['content'] = $content;
        return self::render('layouts/main', $data);
    }
}
