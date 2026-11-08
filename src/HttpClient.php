<?php

declare(strict_types=1);

namespace Shepherd;

/**
 * Minimal outgoing HTTPS client for identity providers and alert webhooks. Uses curl when
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
            throw new \RuntimeException('Outgoing requests (single sign-on, alert webhooks) need the PHP curl extension or allow_url_fopen.');
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
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1]; // the last status line wins
            }
        }
        return ['status' => $status, 'body' => $resp];
    }

    /**
     * Downloads $url to $dest with curl, following redirects only over HTTPS and only
     * to $allowedHosts, and aborting past $maxBytes. Used for Kiosky Shepherd's own
     * updates (GitHub redirects archive downloads to codeload.github.com).
     *
     * @param list<string> $allowedHosts
     * @param array<string, string> $headers
     */
    public static function download(string $url, string $dest, int $maxBytes, array $allowedHosts, array $headers = []): void
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Downloading updates needs the PHP curl extension.');
        }
        $fh = fopen($dest, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot write $dest");
        }
        $lines = ['User-Agent: KioskyShepherd/' . SHEPHERD_VERSION];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOPROGRESS => false,
            // Returning non-zero aborts the transfer. CURLOPT_PROGRESSFUNCTION rather than
            // CURLOPT_XFERINFOFUNCTION, which PHP only has from 8.2.
            CURLOPT_PROGRESSFUNCTION => static fn ($ch, $total, $done) => $done > $maxBytes || $total > $maxBytes ? 1 : 0,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalHost = strtolower((string) parse_url((string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), PHP_URL_HOST));
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($ok === false || $status < 200 || $status >= 300 || !in_array($finalHost, $allowedHosts, true)) {
            @unlink($dest);
            throw new \RuntimeException(match (true) {
                $ok === false => "Download failed: $err",
                !in_array($finalHost, $allowedHosts, true) => "The download was redirected to an unexpected host ($finalHost).",
                default => "The download answered HTTP $status.",
            });
        }
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
