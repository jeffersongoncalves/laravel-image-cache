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
    | After a failed fetch (404, non-image, network error...), warm() skips the
    | upstream for this many seconds instead of retrying on every request.
    | Set to 0 to retry every time.
    |
    */
    'failure_ttl' => (int) env('IMAGE_CACHE_FAILURE_TTL', 3600),

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
];
