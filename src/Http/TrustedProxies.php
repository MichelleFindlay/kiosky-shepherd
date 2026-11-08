<?php

declare(strict_types=1);

namespace Shepherd\Http;

use Shepherd\Config;
use Shepherd\HttpClient;

/**
 * Works out the real client IP and whether the visitor used HTTPS when Kiosky
 * Shepherd sits behind a reverse proxy or CDN (Cloudflare, nginx, Caddy, Traefik,
 * HAProxy, a load balancer…).
 *
 * Forwarding headers are only believed when the request comes from an address in
 * 'trusted_proxies', which accepts single IPs, CIDR ranges (IPv4 and IPv6) and the
 * presets 'cloudflare' and 'private'. Anyone else could simply send the headers.
 */
final class TrustedProxies
{
    /** Cloudflare's published ranges (https://www.cloudflare.com/ips/). Refreshed weekly by cron into storage. */
    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** Loopback and private networks: a proxy on the same machine, in Docker or on the LAN. */
    private const PRIVATE = [
        '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16', '100.64.0.0/10',
        '::1/128', 'fc00::/7', 'fe80::/10',
    ];

    private const CLOUDFLARE_CACHE = 'cache/cloudflare-ips.json';

    /** Headers that carry the client address, by 'client_ip_header' value. */
    public const HEADERS = [
        'x-forwarded-for', 'forwarded', 'cf-connecting-ip', 'true-client-ip', 'x-real-ip', 'fastly-client-ip', 'x-azure-clientip',
    ];

    /** @var list<array{0: string, 1: int}>|null parsed ranges: [packed network, prefix length] */
    private static ?array $ranges = null;
    /** @var list<array{0: string, 1: int}>|null */
    private static ?array $cfRanges = null;

    // ---- Ranges ------------------------------------------------------------------

    /** @return list<string> the configured entries, as written */
    public static function configured(): array
    {
        $v = Config::get('trusted_proxies', []);
        return array_values(array_filter(is_string($v) ? [$v] : (array) $v, 'is_string'));
    }

    /** @return list<string> every CIDR the configuration expands to */
    public static function expanded(): array
    {
        $out = [];
        foreach (self::configured() as $entry) {
            $entry = trim($entry);
            $out = match (strtolower($entry)) {
                'cloudflare' => array_merge($out, self::cloudflareCidrs()),
                'private', 'local' => array_merge($out, self::PRIVATE),
                default => array_merge($out, [$entry]),
            };
        }
        return array_values(array_unique($out));
    }

    /** @return list<array{0: string, 1: int}> */
    private static function ranges(): array
    {
        if (self::$ranges === null) {
            self::$ranges = [];
            foreach (self::expanded() as $cidr) {
                $r = self::parse($cidr);
                if ($r === null) {
                    error_log("[kiosky-shepherd] trusted_proxies: ignoring '$cidr' (not an IP address or CIDR range)");
                    continue;
                }
                self::$ranges[] = $r;
            }
        }
        return self::$ranges;
    }

    /** "10.0.0.0/8" or "2001:db8::1" → [packed address, prefix length], or null. */
    private static function parse(string $cidr): ?array
    {
        [$ip, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
        $packed = @inet_pton((string) $ip);
        if ($packed === false) {
            return null;
        }
        $max = strlen($packed) * 8;
        if ($bits === null || $bits === '') {
            return [$packed, $max];
        }
        if (!ctype_digit($bits) || (int) $bits > $max) {
            return null;
        }
        return [$packed, (int) $bits];
    }

    /**
     * @param list<string> $cidrs
     * @return list<array{0: string, 1: int}>
     */
    private static function parsedList(array $cidrs): array
    {
        return array_values(array_filter(array_map(static fn (string $c) => self::parse($c), $cidrs)));
    }

    /** @param list<array{0: string, 1: int}> $ranges */
    private static function inRanges(string $ip, array $ranges): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($ranges as [$net, $bits]) {
            if (strlen($net) !== strlen($packed)) {
                continue; // IPv4 vs IPv6
            }
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    public static function isTrusted(string $ip): bool
    {
        return self::inRanges($ip, self::ranges());
    }

    public static function isCloudflare(string $ip): bool
    {
        if (self::$cfRanges === null) {
            self::$cfRanges = self::parsedList(self::cloudflareCidrs());
        }
        return self::inRanges($ip, self::$cfRanges);
    }

    // ---- The client --------------------------------------------------------------

    /** The visitor's IP: the nearest address in the forwarding chain that isn't a trusted proxy. */
    public static function clientIp(string $remote, callable $header): string
    {
        if (!self::isTrusted($remote)) {
            return $remote;
        }
        $choice = strtolower((string) Config::get('client_ip_header', 'auto'));
        if ($choice !== 'auto' && in_array($choice, self::HEADERS, true)) {
            return self::fromHeader($choice, $remote, $header) ?? $remote;
        }
        // Auto: Cloudflare's own header when Cloudflare connects directly, then the
        // standard chain headers, then X-Real-IP (nginx's usual single-value header)
        if (self::isCloudflare($remote)) {
            $ip = self::fromHeader('cf-connecting-ip', $remote, $header);
            if ($ip !== null) {
                return $ip;
            }
        }
        foreach (['forwarded', 'x-forwarded-for', 'x-real-ip'] as $h) {
            $ip = self::fromHeader($h, $remote, $header);
            if ($ip !== null) {
                return $ip;
            }
        }
        return $remote;
    }

    private static function fromHeader(string $name, string $remote, callable $header): ?string
    {
        $value = $header($name);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        if ($name === 'x-forwarded-for' || $name === 'forwarded') {
            $chain = $name === 'forwarded' ? self::forwardedFor($value) : array_map('trim', explode(',', $value));
            // Walk from the nearest hop outwards, skipping our own proxies
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $ip = self::cleanIp($chain[$i]);
                if ($ip === null) {
                    return null; // garbage in the chain: don't guess
                }
                if (!self::isTrusted($ip)) {
                    return $ip;
                }
            }
            return null;
        }
        return self::cleanIp($value);
    }

    /** @return list<string> the for= values of an RFC 7239 Forwarded header, in order */
    private static function forwardedFor(string $value): array
    {
        $out = [];
        foreach (explode(',', $value) as $element) {
            if (preg_match('/(?:^|;)\s*for\s*=\s*("?)([^";,]+)\1/i', $element, $m)) {
                $out[] = $m[2];
            }
        }
        return $out;
    }

    /** "203.0.113.5", "[2001:db8::1]:443" or "203.0.113.5:1234" → the bare IP, or null. */
    private static function cleanIp(string $v): ?string
    {
        $v = trim($v, " \t\"");
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $v, $m)) {
            $v = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $v, $m)) {
            $v = $m[1];
        }
        return filter_var($v, FILTER_VALIDATE_IP) !== false ? $v : null;
    }

    /** Whether the visitor used HTTPS, as the nearest trusted proxy says. */
    public static function forwardedHttps(string $remote, callable $header): bool
    {
        if (!self::isTrusted($remote)) {
            return false;
        }
        $proto = strtolower(trim(explode(',', (string) $header('x-forwarded-proto'))[0]));
        if ($proto !== '') {
            return $proto === 'https';
        }
        $fwd = (string) $header('forwarded');
        if (preg_match('/proto\s*=\s*"?(https?)/i', $fwd, $m)) {
            return strtolower($m[1]) === 'https';
        }
        $visitor = json_decode((string) $header('cf-visitor'), true);
        if (is_array($visitor) && isset($visitor['scheme'])) {
            return $visitor['scheme'] === 'https';
        }
        return strtolower((string) $header('x-forwarded-ssl')) === 'on'
            || strtolower((string) $header('front-end-https')) === 'on';
    }

    // ---- Cloudflare's list -------------------------------------------------------

    /** @return list<string> */
    public static function cloudflareCidrs(): array
    {
        $cached = json_decode((string) @file_get_contents(Config::storage(self::CLOUDFLARE_CACHE)), true);
        $list = is_array($cached) && is_array($cached['cidrs'] ?? null) ? $cached['cidrs'] : [];
        $list = array_values(array_filter($list, static fn ($c) => is_string($c) && self::parse($c) !== null));
        return count($list) >= 10 ? $list : self::CLOUDFLARE;
    }

    /** @return array{source: string, updated_at: ?string, count: int} */
    public static function cloudflareInfo(): array
    {
        $cached = json_decode((string) @file_get_contents(Config::storage(self::CLOUDFLARE_CACHE)), true);
        $fresh = is_array($cached) && is_string($cached['updated_at'] ?? null);
        return ['source' => $fresh ? 'downloaded' : 'built-in', 'updated_at' => $fresh ? $cached['updated_at'] : null,
            'count' => count(self::cloudflareCidrs())];
    }

    /**
     * Downloads Cloudflare's current ranges, at most weekly. Only when 'cloudflare' is
     * in trusted_proxies. Called from bin/cron.php.
     */
    public static function refreshCloudflare(): string
    {
        if (!in_array('cloudflare', array_map('strtolower', self::configured()), true)) {
            return 'cloudflare ranges not used';
        }
        $info = self::cloudflareInfo();
        if ($info['updated_at'] !== null && strtotime($info['updated_at']) > time() - 7 * 86400) {
            return 'cloudflare ranges current';
        }
        $data = HttpClient::json('GET', 'https://api.cloudflare.com/client/v4/ips');
        $cidrs = array_merge((array) ($data['result']['ipv4_cidrs'] ?? []), (array) ($data['result']['ipv6_cidrs'] ?? []));
        $cidrs = array_values(array_filter($cidrs, static fn ($c) => is_string($c) && str_contains($c, '/') && self::parse($c) !== null));
        if (count($cidrs) < 10) {
            throw new \RuntimeException('Cloudflare returned an unexpectedly short list; keeping the old one.');
        }
        $dir = dirname(Config::storage(self::CLOUDFLARE_CACHE));
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        file_put_contents(Config::storage(self::CLOUDFLARE_CACHE),
            json_encode(['updated_at' => gmdate('c'), 'cidrs' => $cidrs], JSON_UNESCAPED_SLASHES), LOCK_EX);
        self::$ranges = null;
        self::$cfRanges = null;
        return 'cloudflare ranges updated (' . count($cidrs) . ')';
    }

    // ---- Diagnostics for the Server page -----------------------------------------

    /** What this request looked like, and advice when the setup looks wrong. */
    public static function diagnose(Request $req): array
    {
        $remote = $req->remoteAddr;
        $seen = [];
        foreach (array_merge(self::HEADERS, ['x-forwarded-proto', 'x-forwarded-host', 'cf-visitor', 'cf-ray', 'via']) as $h) {
            $v = $req->header($h);
            if ($v !== null && $v !== '') {
                $seen[$h] = mb_substr($v, 0, 300);
            }
        }
        $trusted = self::isTrusted($remote);
        $viaCloudflare = self::isCloudflare($remote) || isset($seen['cf-ray']);
        $forwarded = array_intersect_key($seen, array_flip(array_merge(self::HEADERS, ['x-forwarded-proto'])));
        $warnings = [];
        if ($forwarded !== [] && !$trusted) {
            $warnings[] = "Requests arrive from $remote with forwarding headers, but $remote isn't in trusted_proxies, so every visitor looks like "
                . "$remote: rate limits, the audit log and device IPs are wrong. Add "
                . (self::isCloudflare($remote) ? "'cloudflare'" : (self::inRanges($remote, self::parsedList(self::PRIVATE)) ? "'private'" : "'$remote'"))
                . ' to trusted_proxies in config/config.php.';
        }
        if ($trusted && $forwarded === []) {
            $warnings[] = "$remote is trusted, but this request had no forwarding headers. Make the proxy send X-Forwarded-For and X-Forwarded-Proto.";
        }
        $https = $req->isHttps();
        if (!$https && str_starts_with((string) Config::get('base_url', ''), 'https://')) {
            $warnings[] = 'This request doesn\'t count as HTTPS, although base_url is https. If a proxy ends TLS, trust it and make it send X-Forwarded-Proto: https.';
        }
        if ($viaCloudflare) {
            $warnings[] = 'Behind Cloudflare: turn off Rocket Loader and Email Address Obfuscation for this site (the console\'s security policy blocks the scripts they inject), and set Browser Cache TTL to "Respect Existing Headers".';
        }
        return [
            'remote_addr' => $remote,
            'client_ip' => $req->ip(),
            'https' => $https,
            'remote_trusted' => $trusted,
            'via' => $viaCloudflare ? 'cloudflare' : ($trusted ? 'proxy' : ($forwarded !== [] ? 'untrusted proxy' : 'direct')),
            'headers' => (object) $seen,
            'trusted_proxies' => self::configured(),
            'ranges' => count(self::ranges()),
            'client_ip_header' => (string) Config::get('client_ip_header', 'auto'),
            'cloudflare' => self::cloudflareInfo(),
            'warnings' => $warnings,
        ];
    }
}
