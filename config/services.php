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

    /*
    | Google Gemini — translates exhibit labels and narrates the audio guides
    | when staff add an exhibit. One Google AI Studio key covers both. With no
    | key set the panel still works; the AI buttons explain what is missing.
    */
    'gemini' => [
        'key'        => env('GEMINI_API_KEY'),
        'text_model' => env('GEMINI_TEXT_MODEL', 'gemini-3.6-flash'),
        'tts_model'  => env('GEMINI_TTS_MODEL', 'gemini-3.1-flash-tts-preview'),
        'tts_voice'  => env('GEMINI_TTS_VOICE', 'Kore'),
        'endpoint'   => env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
