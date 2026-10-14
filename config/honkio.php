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
];
