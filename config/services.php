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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // Lấy đề từ nguồn đề ngoài (chỉ administrator, trang quản trị → Lấy đề).
    'quiz_source' => [
        'url' => env('QUIZ_SOURCE_API_URL'),
        'token' => env('QUIZ_SOURCE_TOKEN'),
        // `bizapp` của "Nguồn chính" trên API nguồn.
        'bizapp' => env('QUIZ_SOURCE_BIZAPP'),
    ],

    'google' => [
        // Phải trùng NEXT_PUBLIC_GOOGLE_CLIENT_ID của frontend (kiểm tra `aud` của token).
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
