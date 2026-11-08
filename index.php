<?php

declare(strict_types=1);

// PHP's built-in server (php -S localhost:8080 index.php): serve only the console's
// static files directly; everything else, including src/ and config/, goes through here.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    if (preg_match('#^/admin(/|$)#', $path) && !str_contains($path, '..')
        && (is_file(__DIR__ . $path) || is_dir(__DIR__ . $path))) {
        return false;
    }
}

if (!is_file(__DIR__ . '/config/config.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Kiosky Shepherd is not configured yet: copy config/config.example.php to config/config.php and fill it in.\n");
}

require __DIR__ . '/src/bootstrap.php';

use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Http\Router;

ini_set('display_errors', '0');

$router = new Router();
(require SHEPHERD_ROOT . '/src/routes.php')($router);

try {
    $request = Request::fromGlobals();
    $response = $router->dispatch($request);
} catch (HttpError $e) {
    $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
    if ($e->details !== []) {
        $error['details'] = $e->details;
    }
    $response = Response::json(['error' => $error], $e->status);
} catch (\Throwable $e) {
    error_log('[kiosky-shepherd] ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    $response = Response::json(['error' => ['code' => 'server_error', 'message' => 'Something went wrong on the server.']], 500);
}

$response->send();
