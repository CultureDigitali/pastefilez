<?php
declare(strict_types=1);

define('APP_START', microtime(true));
define('APP_ROOT', dirname(__DIR__, 2));
define('APP_PATH', APP_ROOT . '/app');
define('STORAGE_PATH', APP_ROOT . '/storage');
define('PUBLIC_PATH', APP_ROOT . '/public');

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $rel = str_replace('\\', '/', substr($class, 4)) . '.php';
        $file = APP_PATH . '/' . $rel;
        if (is_file($file)) require $file;
    }
});

require __DIR__ . '/helpers.php';
