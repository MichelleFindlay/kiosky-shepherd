<?php

declare(strict_types=1);

namespace Shepherd\Http;

use Shepherd\Config;

final class Request
{
    /** @var array<string, string> route parameters filled in by the router */
    public array $params = [];

    private ?array $jsonBody = null;

    /**
     * @param array<string, string> $headers lower-cased names
     * @param array<string, mixed> $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        private readonly string $rawBody,
        public readonly string $remoteAddr,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        // Some Apache/FastCGI setups hide the Authorization header
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');

        // multipart bodies are already parsed into $_POST/$_FILES and php://input is empty
        $isMultipart = str_starts_with(strtolower($headers['content-type'] ?? ''), 'multipart/');
        $raw = $isMultipart ? '' : (string) file_get_contents('php://input');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $headers,
            $raw,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearer(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            return $m[1];
        }
        return null;
    }

    /** The JSON request body as an associative array (empty when there is none). */
    public function json(): array
    {
        if ($this->jsonBody === null) {
            if (trim($this->rawBody) === '') {
                $this->jsonBody = [];
            } else {
                try {
                    $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw HttpError::badRequest('The request body is not valid JSON.');
                }
                if (!is_array($decoded)) {
                    throw HttpError::badRequest('The request body must be a JSON object.');
                }
                $this->jsonBody = $decoded;
            }
        }
        return $this->jsonBody;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $body = $this->json();
        return array_key_exists($key, $body) ? $body[$key] : $default;
    }

    public function queryInt(string $key, int $default, int $min, int $max): int
    {
        $v = $this->query[$key] ?? null;
        if ($v === null || $v === '' || !is_numeric($v)) {
            return $default;
        }
        return max($min, min($max, (int) $v));
    }

    public function queryString(string $key): ?string
    {
        $v = $this->query[$key] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function param(string $key): string
    {
        return $this->params[$key] ?? '';
    }

    public function intParam(string $key): int
    {
        $v = $this->params[$key] ?? '';
        if (!ctype_digit($v)) {
            throw HttpError::notFound();
        }
        return (int) $v;
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /** Client IP, honouring X-Forwarded-For only from configured proxies. */
    public function ip(): string
    {
        $trusted = (array) Config::get('trusted_proxies', []);
        if ($trusted !== [] && in_array($this->remoteAddr, $trusted, true)) {
            $fwd = $this->header('x-forwarded-for');
            if ($fwd !== null) {
                $parts = array_map('trim', explode(',', $fwd));
                // Walk from the right, skipping our own proxies
                for ($i = count($parts) - 1; $i >= 0; $i--) {
                    if (!in_array($parts[$i], $trusted, true) && filter_var($parts[$i], FILTER_VALIDATE_IP)) {
                        return $parts[$i];
                    }
                }
            }
        }
        return $this->remoteAddr;
    }

    public function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        $trusted = (array) Config::get('trusted_proxies', []);
        return in_array($this->remoteAddr, $trusted, true)
            && strtolower((string) $this->header('x-forwarded-proto')) === 'https';
    }
}
