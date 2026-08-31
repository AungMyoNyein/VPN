<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Activation rate limit
    |--------------------------------------------------------------------------
    |
    | Requests per minute allowed for POST /api/v1/activate, keyed by
    | IP + hash(customer_code). See RateLimiter::for('activate', ...) in
    | AppServiceProvider.
    |
    */
    'activate_per_minute' => (int) env('ACTIVATION_RATE_LIMIT_PER_MINUTE', 5),

    /*
    |--------------------------------------------------------------------------
    | Device credential time-to-live
    |--------------------------------------------------------------------------
    |
    | Number of days a device credential remains valid before it must be
    | refreshed via POST /api/v1/device/refresh. Null (default) means
    | credentials do not expire by time — they are only invalidated by
    | rotation or revocation.
    |
    */
    'device_credential_ttl_days' => env('DEVICE_CREDENTIAL_TTL_DAYS') !== null
        ? (int) env('DEVICE_CREDENTIAL_TTL_DAYS')
        : null,

];
