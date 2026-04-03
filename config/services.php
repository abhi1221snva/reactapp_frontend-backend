<?php

return [

   'sendgrid' => [
        'api_key' => env('SENDGRID_API_KEY'),
    ],
    'phonify' => [
        'app_token' => env('PHONIFY_APP_TOKEN'),
        'easify_url'=>env('EASIFY_URL')
    ],
     'google' =>[
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'gmail_redirect' => env('GOOGLE_GMAIL_REDIRECT_URI'),
        'gmail_scopes' => [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.modify',
            'https://www.googleapis.com/auth/gmail.send',
            'email',
            'profile',
        ],
        'calendar_redirect' => env('GOOGLE_CALENDAR_REDIRECT_URI', env('GOOGLE_REDIRECT_URI')),
        'calendar_scopes' => [
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/calendar.events',
            'email',
            'profile',
        ],
    ],
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model'   => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
    ],

];
