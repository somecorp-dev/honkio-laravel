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
];
