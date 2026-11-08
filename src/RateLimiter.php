<?php

declare(strict_types=1);

namespace Shepherd;

use Shepherd\Http\HttpError;

/** Fixed-window rate limiting stored in MySQL, so it works across PHP workers. */
final class RateLimiter
{
    public static function hit(string $bucket, int $limit, int $windowSeconds): void
    {
        $bucket = substr(hash('sha256', $bucket), 0, 40) . ':' . substr($bucket, 0, 80);
        Db::run(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (:b, NOW(), 1)
             ON DUPLICATE KEY UPDATE
                hits = IF(window_start < NOW() - INTERVAL :w SECOND, 1, hits + 1),
                window_start = IF(window_start < NOW() - INTERVAL :w2 SECOND, NOW(), window_start)',
            ['b' => $bucket, 'w' => $windowSeconds, 'w2' => $windowSeconds]
        );
        $hits = (int) Db::value('SELECT hits FROM rate_limits WHERE bucket = :b', ['b' => $bucket]);
        if ($hits > $limit) {
            throw new HttpError(429, 'rate_limited', 'Too many attempts. Wait a few minutes and try again.');
        }
    }

    public static function clear(string $bucket): void
    {
        $bucket = substr(hash('sha256', $bucket), 0, 40) . ':' . substr($bucket, 0, 80);
        Db::run('DELETE FROM rate_limits WHERE bucket = :b', ['b' => $bucket]);
    }
}
