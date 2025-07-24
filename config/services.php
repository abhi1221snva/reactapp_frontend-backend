<?php

return [

   'sendgrid' => [
        'api_key' => env('SENDGRID_API_KEY'),
    ],
    'google' =>[
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ]
];
