<?php

return [

   'sendgrid' => [
        'api_key' => env('SENDGRID_API_KEY'),
    ],
    'google' =>[
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => 'http://127.0.0.1:8000/auth/google/callback',
    ]
];
