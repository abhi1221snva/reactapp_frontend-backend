<?php

return [
    'project_id' => env('FIREBASE_PROJECT_ID'),
    'credentials_file' => base_path(env('FIREBASE_CREDENTIALS_PATH', 'storage/app/firebase-service-account.json')),
];
