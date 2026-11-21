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

    'google_maps' => [
        'key' => env('GOOGLE_MAP_API_KEY'),
    ],

    'sms_gateway' => [
        'url' => rtrim((string) env('SMS_GATEWAY_URL', ''), '/'),
        'admin_token' => env('SMS_GATEWAY_ADMIN_TOKEN'),
        'device_id' => env('SMS_GATEWAY_DEVICE_ID'),
        'otp_length' => (int) env('SMS_GATEWAY_OTP_LENGTH', 6),
        'otp_ttl' => (int) env('SMS_GATEWAY_OTP_TTL', 300),
        'resend_cooldown' => (int) env('SMS_GATEWAY_OTP_RESEND_COOLDOWN', 60),
        'verification_token_ttl' => (int) env('SMS_GATEWAY_VERIFICATION_TOKEN_TTL', 900),
        'timeout' => (int) env('SMS_GATEWAY_TIMEOUT', 15),
        'message_template' => env(
            'SMS_GATEWAY_OTP_TEMPLATE',
            'Your MDRRMO verification code is {{code}}. Valid for 5 minutes.'
        ),
    ],

];
