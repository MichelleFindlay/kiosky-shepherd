<?php

declare(strict_types=1);

define('SHEPHERD_ROOT', dirname(__DIR__));
define('SHEPHERD_VERSION', '1.1.0');

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit("Kiosky Shepherd needs PHP 8.1 or later.\n");
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Shepherd\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = SHEPHERD_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

\Shepherd\Config::load(SHEPHERD_ROOT . '/config/config.php');
date_default_timezone_set('UTC');
