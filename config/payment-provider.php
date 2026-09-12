<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider Name
    |--------------------------------------------------------------------------
    |
    | Stored on each order so the provider's payment ID is unique per provider.
    |
    */

    'name' => 'payment-provider',

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

    /*
    |--------------------------------------------------------------------------
    | Failure Alerts
    |--------------------------------------------------------------------------
    |
    | While webhook failures are unresolved, webhooks:check-failures emails a
    | summary to this address, at most once per alert interval, so a person
    | is told instead of the failure waiting in a table.
    |
    */

    'alert_email' => env('PAYMENT_WEBHOOK_ALERT_EMAIL'),

    'alert_interval_minutes' => 60,

];
