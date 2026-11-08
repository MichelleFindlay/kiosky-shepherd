<?php

declare(strict_types=1);

namespace Shepherd;

/**
 * Minimal outgoing HTTPS client for talking to identity providers. Uses curl when
 * available and PHP streams otherwise (which need allow_url_fopen). TLS
 * certificates are always verified.
 */
final class HttpClient
{
    private const TIMEOUT = 10;
    private const MAX_BYTES = 1048576;

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $headers += ['Accept' => 'application/json', 'User-Agent' => 'KioskyShepherd/' . SHEPHERD_VERSION];
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $resp = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                throw new \RuntimeException("Request to $url failed: $err");
            }
            return ['status' => $status, 'body' => substr((string) $resp, 0, self::MAX_BYTES)];
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException('Single sign-on needs the PHP curl extension or allow_url_fopen.');
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $lines),
                'content' => $body ?? '',
                'timeout' => self::TIMEOUT,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $resp = @file_get_contents($url, false, $ctx, 0, self::MAX_BYTES);
        if ($resp === false) {
            throw new \RuntimeException("Request to $url failed.");
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1]; // the last status line wins
            }
        }
        return ['status' => $status, 'body' => $resp];
    }

    /** GET or POST that must return a JSON object with a 2xx status. */
    public static function json(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $r = self::request($method, $url, $headers, $body);
        $data = json_decode($r['body'], true);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            $detail = is_array($data) ? trim(($data['error'] ?? '') . ': ' . ($data['error_description'] ?? ''), ': ') : '';
            throw new \RuntimeException("$url answered HTTP {$r['status']}" . ($detail !== '' ? " ($detail)" : ''));
        }
        if (!is_array($data)) {
            throw new \RuntimeException("$url did not return a JSON object.");
        }
        return $data;
    }
}
