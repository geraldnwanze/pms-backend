<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID keys
    |--------------------------------------------------------------------------
    |
    | Identify this application server to push services. Generate a pair with:
    |
    |   php -r 'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
    |
    | The public key is also exposed to the browser (it subscribes with it);
    | the private key must stay secret.
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@example.com'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
    | Default TTL (seconds) a push service should hold an undelivered message.
    */
    'ttl' => (int) env('WEBPUSH_TTL', 43200),

];
