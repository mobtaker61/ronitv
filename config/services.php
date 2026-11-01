<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'bunny' => [
        'stream_library_id' => env('BUNNY_STREAM_LIBRARY_ID'),
        'stream_access_key' => env('BUNNY_STREAM_ACCESS_KEY'),
        'shahrokh_collection_id' => env('BUNNY_SHAHROKH_COLLECTION_ID', 'shahrokh'),
    ],

    // Online Persian subtitles (https://subsource.net/api-docs). Free keys: 60/min, 1,800/h, 7,200/day.
    'subsource' => [
        'key' => env('SUBSOURCE_API_KEY'),
        'base_url' => env('SUBSOURCE_BASE_URL', 'https://api.subsource.net/api/v1'),
        'language' => 'farsi_persian',
        // Titles with no match are not looked up again for this many days.
        'miss_days' => (int) env('SUBSOURCE_MISS_DAYS', 7),
    ],

    'tmdb' => [
        'key' => env('TMDB_API_KEY'),
        'cache_ttl' => (int) env('TMDB_CACHE_TTL', 604800), // 7 days
    ],

];
