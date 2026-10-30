<?php

return [
    /*
     * Your HonkIO API key (mk_live_... or mk_test_...).
     */
    'api_key' => env('HONKIO_API_KEY'),

    /*
     * Override only to point at another API host.
     */
    'base_url' => env('HONKIO_BASE_URL', 'https://api.honkio.ca'),

    /*
     * Default sending number (E.164) for the SMS notification channel.
     * A HonkioMessage can override it with ->from().
     */
    'sms_from' => env('HONKIO_SMS_FROM'),

    /*
     * How often the client retries a refusal that waiting fixes (the sending
     * number's per-minute limit, the carrier asking to slow down or being
     * unreachable) or a connection error. 0 turns retrying off.
     */
    'max_retries' => env('HONKIO_MAX_RETRIES', 2),

    /*
     * The longest single wait, in seconds, a retry waits out. A longer wait
     * is not waited: the exception is thrown as usual.
     */
    'max_retry_delay' => env('HONKIO_MAX_RETRY_DELAY', 60),

    /*
     * Honkio Auth (not available yet): signing users in to this application.
     * Copy the values from your app in the dashboard. Settings in
     * config/services.php under 'honkio' (the Socialite convention) win
     * over these.
     */
    'auth' => [
        // The app's issuer: https:// plus its hostname, such as https://login.example.ca.
        'issuer' => env('HONKIO_AUTH_ISSUER'),
        'client_id' => env('HONKIO_AUTH_CLIENT_ID'),
        // Leave empty for a public client.
        'client_secret' => env('HONKIO_AUTH_CLIENT_SECRET'),
        // Your callback route; a path is resolved against APP_URL. It must be one of the app's redirect URIs.
        'redirect' => env('HONKIO_AUTH_REDIRECT_URI', '/auth/honkio/callback'),
        'scopes' => ['openid', 'email'],
        // The cache store for the app's signing keys (null: the default store).
        // Use one every worker shares, so an outage finds the keys cached.
        'cache_store' => env('HONKIO_AUTH_CACHE_STORE'),
        // Where the honkio.auth middleware sends a signed-out browser (a route name).
        'login_route' => 'login',
        // A guard to log out too when Honkio ends the session (your own users' guard, such as 'web').
        'logout_guard' => env('HONKIO_AUTH_LOGOUT_GUARD'),
    ],
];
