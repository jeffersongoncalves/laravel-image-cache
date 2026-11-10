<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum number of seconds ImageCache::warm() may spend fetching the
    | remote image before giving up.
    |
    */
    'timeout' => (int) env('IMAGE_CACHE_TIMEOUT', 8),

    /*
    |--------------------------------------------------------------------------
    | Maximum Redirects
    |--------------------------------------------------------------------------
    |
    | How many redirect hops warm() will follow. Every hop is re-validated
    | against SsrfGuard::resolveEntries(), so a public host that redirects to
    | an internal one (e.g. 169.254.169.254 / localhost) is still blocked.
    |
    */
    'max_redirects' => (int) env('IMAGE_CACHE_MAX_REDIRECTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Failure TTL
    |--------------------------------------------------------------------------
    |
    | After a failed fetch, warm() skips the upstream instead of retrying on
    | every request. failure_ttl covers transient failures (timeout, 5xx, 403,
    | 429...); a 429/503 Retry-After longer than it is honoured.
    | permanent_failure_ttl covers failures a retry won't fix (404, 410,
    | non-image, oversized). Set either to 0 to retry that class every time;
    | failure_ttl = 0 also ignores Retry-After.
    |
    */
    'failure_ttl' => (int) env('IMAGE_CACHE_FAILURE_TTL', 3600),

    'permanent_failure_ttl' => (int) env('IMAGE_CACHE_PERMANENT_FAILURE_TTL', 7 * 86400),

    /*
    |--------------------------------------------------------------------------
    | Maximum Bytes
    |--------------------------------------------------------------------------
    |
    | Largest image warm() will store. A bigger Content-Length, or a body that
    | grows past it mid-transfer, aborts the fetch and counts as a failure.
    | Set to 0 for no limit.
    |
    */
    'max_bytes' => (int) env('IMAGE_CACHE_MAX_BYTES', 10 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | Laravel cache store that remembers each key's "fresh until" time and
    | pointer, so a hit on a remote disk (S3/R2) costs a single read instead
    | of several billed round trips. null = the app's default store; "array"
    | effectively turns it off.
    |
    */
    'cache_store' => env('IMAGE_CACHE_STORE'),
];
