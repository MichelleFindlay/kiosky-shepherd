<?php

declare(strict_types=1);

namespace Shepherd\Http;

final class Router
{
    /** @var list<array{string, string, string, callable}> method, regex, pattern, handler */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [strtoupper($method), $regex, $pattern, $handler];
    }

    public function get(string $p, callable $h): void    { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void   { $this->add('POST', $p, $h); }
    public function patch(string $p, callable $h): void  { $this->add('PATCH', $p, $h); }
    public function put(string $p, callable $h): void    { $this->add('PUT', $p, $h); }
    public function delete(string $p, callable $h): void { $this->add('DELETE', $p, $h); }

    public function dispatch(Request $req): Response
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, , $handler]) {
            if (!preg_match($regex, $req->path, $m)) {
                continue;
            }
            $verb = $req->method === 'HEAD' ? 'GET' : $req->method;
            if ($method !== $verb) {
                $allowed[] = $method;
                continue;
            }
            $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($req);
        }
        if ($allowed !== []) {
            throw new HttpError(405, 'method_not_allowed', 'Use ' . implode(', ', array_unique($allowed)) . '.');
        }
        throw HttpError::notFound('Endpoint');
    }
}
