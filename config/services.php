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

    // ── Phase 8: controlled agentic workflows ────────────────────────
    // STEP 30: the minimum configuration required — enable/disable per
    // workflow and the daily run time. No user-facing workflow builder;
    // these are the only three workflows and they are not
    // user-editable. See docs/WORKFLOWS.md.
    'workflows' => [
        'daily_follow_up_review' => [
            'enabled' => env('WORKFLOW_DAILY_FOLLOW_UP_REVIEW_ENABLED', true),
        ],
        'opportunity_attention_review' => [
            'enabled' => env('WORKFLOW_OPPORTUNITY_ATTENTION_REVIEW_ENABLED', true),
        ],
        'performance_exception_review' => [
            'enabled' => env('WORKFLOW_PERFORMANCE_EXCEPTION_REVIEW_ENABLED', true),
        ],
        // 24-hour "HH:MM" the daily scheduled run fires at.
        'run_at' => env('WORKFLOW_RUN_AT', '08:00'),
        // How many days a workflow-produced approval remains actionable
        // before it's treated as expired (STEP 39).
        'approval_ttl_days' => env('WORKFLOW_APPROVAL_TTL_DAYS', 3),
        // Deterministic thresholds (STEP 37) — plain Laravel business
        // rules, never left to the model to decide.
        'stalled_opportunity_days' => env('WORKFLOW_STALLED_OPPORTUNITY_DAYS', 14),
        'closing_soon_days' => env('WORKFLOW_CLOSING_SOON_DAYS', 7),
    ],

    // ── Phase 12: Cost-to-Serve (revenue & engagement) intelligence ──
    // No cost data exists anywhere in this application's schema (see
    // docs/COST_TO_SERVE.md's data-availability matrix) — everything
    // here is revenue/engagement-side only. Thresholds are deterministic
    // application config, never left to the model to invent (STEP 11
    // of the phase spec).
    'cost_to_serve' => [
        // Which Opportunity currency to aggregate — mirrors
        // PerformanceService's own convention (STEP 8/Phase 4) of never
        // summing mixed currencies.
        'default_currency' => env('COST_TO_SERVE_DEFAULT_CURRENCY', 'USD'),
        // An account's revenue this period vs. last period dropping by
        // at least this percentage is flagged for review.
        'revenue_decline_threshold_percent' => env('COST_TO_SERVE_REVENUE_DECLINE_THRESHOLD', 20.0),
        // Sales-engagement touches (activities + communications) rising
        // by at least this percentage while revenue does not rise is
        // flagged — "more effort, no more return" is a legitimate
        // signal from real data, distinct from any cost claim.
        'engagement_growth_threshold_percent' => env('COST_TO_SERVE_ENGAGEMENT_GROWTH_THRESHOLD', 50.0),
        // An account with zero closed revenue in the period but at
        // least this many logged engagement touches is flagged.
        'zero_revenue_engagement_threshold' => env('COST_TO_SERVE_ZERO_REVENUE_ENGAGEMENT_THRESHOLD', 5),
        // How many top/flagged accounts a single tool call returns —
        // keeps the LLM's context bounded regardless of dataset size
        // (STEP 29).
        'max_accounts_per_query' => env('COST_TO_SERVE_MAX_ACCOUNTS_PER_QUERY', 20),
    ],

];
