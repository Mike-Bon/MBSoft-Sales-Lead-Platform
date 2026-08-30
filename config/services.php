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

    // ── Phase 13: Business Development Intelligence ──────────────────
    // Transparent prospecting/account-development analysis over data
    // that already exists (leads, opportunities, activities, follow-up
    // dates). Every threshold and every prioritisation weight is
    // deterministic application config here — never decided by the
    // model, and every score the tools return is explained factor by
    // factor (spec §13: "do not create a mysterious black-box score").
    // See docs/BUSINESS_DEVELOPMENT.md.
    'business_development' => [
        // A still-open lead with no logged activity AND no upcoming
        // follow-up for at least this many days is "going cold".
        'stale_lead_days' => env('BD_STALE_LEAD_DAYS', 10),
        // An open opportunity with no logged activity for at least this
        // many days is flagged as stalled / at risk.
        'stalled_opportunity_days' => env('BD_STALLED_OPPORTUNITY_DAYS', 21),
        // A "recent engagement" prioritisation bonus applies when the
        // lead has a logged activity within this many days.
        'recent_engagement_days' => env('BD_RECENT_ENGAGEMENT_DAYS', 7),
        // Max rows any single BD tool call returns — keeps the LLM's
        // context bounded regardless of dataset size (spec §27).
        'max_results_per_query' => env('BD_MAX_RESULTS_PER_QUERY', 25),

        // Lead-prioritisation factor weights. The score is the plain sum
        // of whichever of these apply; the tool always returns the
        // matched factors alongside the number so a human can check the
        // arithmetic themselves.
        'weights' => [
            'status_qualified' => 4,
            'status_contacted' => 2,
            'priority_high' => 3,
            'priority_medium' => 1,
            'follow_up_overdue' => 4,
            'follow_up_missing' => 2,
            'recent_engagement' => 2,
            'no_engagement_ever' => 1,
            'has_open_opportunity' => 3,
            'high_estimated_value' => 2,
        ],
        // "high_estimated_value" factor applies at or above this amount
        // (in the lead's own currency — never converted or mixed).
        'high_value_threshold' => env('BD_HIGH_VALUE_THRESHOLD', 50000),
        // Score bands for the human-readable priority label.
        'bands' => [
            'high' => env('BD_BAND_HIGH', 8),
            'medium' => env('BD_BAND_MEDIUM', 4),
        ],
    ],

    // ── V2.1: External web search provider ──────────────────────────
    // Provider abstraction for Market Intelligence prospect discovery.
    // Bound in AppServiceProvider: 'brave' -> BraveSearchProvider,
    // anything else (or unset) -> NullSearchProvider (discovery reports
    // "not configured" and never 500s). No credential is ever logged.
    // See docs/MARKET_INTELLIGENCE.md.
    'search' => [
        'provider' => env('SEARCH_PROVIDER'),
        'timeout' => env('SEARCH_HTTP_TIMEOUT', 15),
        'brave' => [
            'api_key' => env('BRAVE_SEARCH_API_KEY', ''),
            // Optional ISO country bias for results, e.g. "PH". Blank = global.
            'country' => env('BRAVE_SEARCH_COUNTRY'),
        ],
    ],

    // ── V2.1: Market Intelligence — External Prospect Discovery ─────
    // Every external effect is bounded by these limits and nowhere else
    // (spec §3/§5). Discovery is DISCOVERY ONLY — no scoring, no CRM
    // writes, no outreach. See docs/MARKET_INTELLIGENCE.md.
    'market_intelligence' => [
        // Hard cap on candidates any one discovery call returns.
        'max_results' => env('MARKET_INTELLIGENCE_MAX_RESULTS', 20),
        // Max deterministic search queries built per discovery call.
        'max_searches' => env('MARKET_INTELLIGENCE_MAX_SEARCHES', 3),
        // Results requested from the provider per query.
        'results_per_search' => env('MARKET_INTELLIGENCE_RESULTS_PER_SEARCH', 8),
        // Max public pages fetched for evidence per discovery call.
        'max_fetches' => env('MARKET_INTELLIGENCE_MAX_FETCHES', 12),
        // Per-page fetch timeout, seconds.
        'fetch_timeout' => env('MARKET_INTELLIGENCE_FETCH_TIMEOUT', 8),
        // Per-user discovery calls allowed per rolling hour.
        'max_discoveries_per_hour' => env('MARKET_INTELLIGENCE_MAX_PER_HOUR', 12),

        // ── V2.2: Prospect Qualification & Evidence ────────────────
        // Max businesses one qualification call evaluates.
        'max_qualification_prospects' => env('MARKET_INTELLIGENCE_MAX_QUALIFY_PROSPECTS', 8),
        // Batch-wide cap on ADDITIONAL searches done while qualifying
        // (only for unresolved hard criteria) — spec §18.
        'max_qualification_searches' => env('MARKET_INTELLIGENCE_MAX_QUALIFY_SEARCHES', 6),
        // Batch-wide cap on additional page fetches while qualifying.
        'max_qualification_fetches' => env('MARKET_INTELLIGENCE_MAX_QUALIFY_FETCHES', 8),
        // Per-user qualification calls allowed per rolling hour.
        'max_qualifications_per_hour' => env('MARKET_INTELLIGENCE_MAX_QUALIFY_PER_HOUR', 12),
    ],

];
