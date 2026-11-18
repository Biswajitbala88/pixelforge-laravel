<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pixelforge' => [
        'base_url' => env('PIXELFORGE_BASE_URL', 'https://promptedit.dev'),
        'api_key' => env('PIXELFORGE_API_KEY'),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
        // Webhook timestamp tolerance (seconds) for replay protection.
        'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),
        // Max generations we allow in flight (queued/processing) at Pixelforge at once.
        'max_in_flight' => (int) env('PIXELFORGE_MAX_IN_FLIGHT', 3),
        // Max provider-error retries (429/503/5xx/network) before we give up on a submission.
        'max_submit_attempts' => (int) env('PIXELFORGE_MAX_SUBMIT_ATTEMPTS', 6),
        'pricing_cache_seconds' => (int) env('PIXELFORGE_PRICING_CACHE_SECONDS', 300),
        'timeout' => (int) env('PIXELFORGE_TIMEOUT', 15),
    ],

];
