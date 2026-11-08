<?php

declare(strict_types=1);

namespace Shepherd\Http;

final class Response
{
    /** @param array<string, string> $headers */
    private function __construct(
        public readonly int $status,
        public readonly array $headers,
        private readonly ?string $body,
        private readonly ?string $filePath = null,
    ) {
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers, $body);
    }

    public static function noContent(): self
    {
        return new self(204, [], null);
    }

    public static function redirect(string $to): self
    {
        return new self(302, ['Location' => $to], null);
    }

    public static function file(string $path, string $contentType, ?string $downloadName = null, array $headers = []): self
    {
        if (!is_file($path)) {
            throw HttpError::notFound('File');
        }
        $h = [
            'Content-Type' => $contentType,
            'Content-Length' => (string) filesize($path),
        ] + $headers;
        if ($downloadName !== null) {
            $h['Content-Disposition'] = 'attachment; filename="' . addcslashes($downloadName, "\"\\") . '"';
        }
        return new self(200, $h, null, $path);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        if (!isset($this->headers['Cache-Control'])) {
            header('Cache-Control: no-store');
        }
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->filePath !== null) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            readfile($this->filePath);
        } elseif ($this->body !== null) {
            echo $this->body;
        }
    }
}
