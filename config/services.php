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

    // تسجيل الدخول الاجتماعي (Phase 3.5 / ADR-036). الأسرار في .env فقط (المبدأ 5).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', '/auth/facebook/callback'),
    ],

    // مساعد المحتوى بالذكاء الاصطناعي (Phase 6 / ADR-044). الأسرار في .env فقط (المبدأ 5).
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

    // شركة التوصيل Opost (طبقة التكامل — المبدأ 13). الأسرار في .env فقط، لا تُرفع للـGit.
    'opost' => [
        'base_url' => env('OPOST_BASE_URL', 'https://opost.ps/api'),
        // OAuth2 (Password Grant): التوكن يُصدَر ويُجدَّد آليًا — لا حاجة لتوكن ثابت.
        'oauth_url' => env('OPOST_OAUTH_URL', 'https://opost.ps/oauth/token'),
        'client_id' => env('OPOST_CLIENT_ID'),
        'client_secret' => env('OPOST_CLIENT_SECRET'),
        'username' => env('OPOST_USERNAME'),   // بريد الحساب في Opost
        'password' => env('OPOST_PASSWORD'),
        'scope' => env('OPOST_SCOPE', ''),
        // توكن ثابت اختياري (تجاوز يدوي/طوارئ) — يُستخدم فقط إن لم تُضبط بيانات OAuth.
        'token' => env('OPOST_API_TOKEN'),
        'business_id' => env('OPOST_BUSINESS_ID'),
        'business_address_id' => env('OPOST_BUSINESS_ADDRESS_ID'),
        'shipment_type_id' => env('OPOST_SHIPMENT_TYPE_ID', 1),
        // المحافظة الحاوية لمدن المزوّد المستوردة (تُبنى تلقائيًا عند المزامنة).
        'country_name' => env('OPOST_COUNTRY_NAME', 'فلسطين'),
        'country_code' => env('OPOST_COUNTRY_CODE', 'PS'),
    ],

];
