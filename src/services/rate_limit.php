<?php
/**
 * Fixed-window rate limiter backed by the `rate_limits` table.
 * Returns true if the request is ALLOWED, false if the limit is exceeded.
 */
declare(strict_types=1);

function rate_limit(string $bucket, int $maxHits, int $windowSeconds): bool
{
    $bucket = substr(hash('sha256', $bucket), 0, 64);
    $now = time();
    $windowStart = $now - ($now % $windowSeconds);
    q('INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)
       ON DUPLICATE KEY UPDATE
         hits = IF(window_start = VALUES(window_start), hits + 1, 1),
         window_start = VALUES(window_start)', [$bucket, $windowStart]);
    $hits = (int) q_val('SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket]);

    if (random_int(1, 200) === 1) {   // occasional cleanup of stale buckets
        q('DELETE FROM rate_limits WHERE window_start < ?', [$now - 86400]);
    }
    return $hits <= $maxHits;
}
