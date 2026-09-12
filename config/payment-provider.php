<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook Signing Secret
    |--------------------------------------------------------------------------
    |
    | The shared secret used to verify the HMAC-SHA256 signature sent in the
    | Payment-Signature header. Requests are rejected while it is empty.
    |
    */

    'webhook_secret' => env('PAYMENT_PROVIDER_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Signature Tolerance
    |--------------------------------------------------------------------------
    |
    | The maximum number of seconds between the signed timestamp and the time
    | the request is received. Older or future-dated signatures are rejected
    | to limit replay of captured requests.
    |
    */

    'signature_tolerance' => 300,

];
