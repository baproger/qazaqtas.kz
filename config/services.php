<?php

return [

    /*
     * ИИ-генерация SEO (Anthropic). Без ключа работает шаблонный генератор —
     * витрина и ERP ничего внешнего не требуют.
     */
    'anthropic' => [
        // Ключ ИИ вводится в Настройках ERP и лежит в базе; переменная
        // окружения осталась запасным путём. Ключ может быть от Google
        // Gemini (AIza…) или от Anthropic (sk-ant…) — провайдер
        // определяется по его виду, см. App\Support\AiKey.
        'key' => env('AI_API_KEY'),
        'model' => env('SEO_AI_MODEL', 'claude-opus-5'),
        // ИИ-помощник руководителя: своя модель (можно удешевить, не трогая
        // SEO) и дневной лимит вопросов на пользователя; 0 — без лимита.
        'assistant_model' => env('AI_ASSISTANT_MODEL', 'claude-opus-5'),
        'daily_limit' => (int) env('AI_ASSISTANT_DAILY_LIMIT', 100),
    ],


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

    // Web Push (уведомления чата при закрытой вкладке): пара VAPID-ключей.
    // Одна и та же пара на локали и проде — подписки браузеров привязаны к ключу.
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

];
