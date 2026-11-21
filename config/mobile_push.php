<?php

return [
    'enabled' => env('MOBILE_PUSH_ENABLED', true),

    'apns' => [
        'enabled' => env('APNS_ENABLED', false),
        'key_id' => env('APNS_KEY_ID'),
        'team_id' => env('APNS_TEAM_ID'),
        'bundle_id' => env('APNS_BUNDLE_ID', 'com.smartcampus.ios'),
        'private_key_path' => env('APNS_PRIVATE_KEY_PATH'),
        'sandbox' => env('APNS_SANDBOX', true),
    ],

    'fcm' => [
        'enabled' => env('FCM_ENABLED', false),
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials_path' => env('FCM_CREDENTIALS_PATH'),
    ],

    'android_webhook' => [
        'url' => env('ANDROID_PUSH_WEBHOOK_URL'),
        'secret' => env('ANDROID_PUSH_WEBHOOK_SECRET'),
    ],
];
