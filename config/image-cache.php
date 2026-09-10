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
];
