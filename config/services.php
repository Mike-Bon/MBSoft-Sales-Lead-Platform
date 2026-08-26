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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ── Phase 6: Gmail (Google OAuth2) ──────────────────────────────
    // Per-user OAuth2 app credentials (STEP 6/7) — never a stored Gmail
    // password. See GoogleOAuthController and docs/COMMUNICATIONS.md.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // ── Phase 6: WhatsApp Business Platform (Cloud API) ─────────────
    // App-wide System User credentials (STEP 11: per-app/WABA, not
    // per-phone-number — see WhatsAppBusinessNumber's docblock).
    'whatsapp' => [
        'access_token' => env('WHATSAPP_API_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v20.0'),
    ],

    // ── Phase 7: AI provider (Anthropic Claude) ─────────────────────
    // LLM_PROVIDER/LLM_API_KEY/LLM_MODEL/LLM_MAX_TOKENS were reserved as
    // generic names since Phase 1; the concrete first implementation is
    // Anthropic (AppServiceProvider binds App\Contracts\Ai\LlmProvider
    // to App\Services\Ai\Providers\AnthropicProvider). Never hard-coded
    // elsewhere — swapping provider/model only ever touches this file
    // and the .env value. See docs/AI_ASSISTANT.md.
    'anthropic' => [
        'api_key' => env('LLM_API_KEY'),
        'model' => env('LLM_MODEL', 'claude-sonnet-4-5-20250929'),
        'max_tokens' => env('LLM_MAX_TOKENS', 1024),
        'timeout' => env('LLM_TIMEOUT_SECONDS', 30),
    ],

    // Agent execution safeguards (STEP 27) — not provider-specific.
    'ai' => [
        'max_tool_iterations' => env('AI_MAX_TOOL_ITERATIONS', 6),
        'max_message_length' => env('AI_MAX_MESSAGE_LENGTH', 2000),
        'history_turns' => env('AI_HISTORY_TURNS', 6),
    ],

];
