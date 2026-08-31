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

    // ── Phase 7 / V2.0.0: LLM provider (provider-neutral) ───────────
    // LLM_PROVIDER/LLM_API_KEY/LLM_MODEL/LLM_MAX_TOKENS/LLM_TIMEOUT_SECONDS
    // have been generic since Phase 1. AppServiceProvider binds
    // App\Contracts\Ai\LlmProvider to the class named by 'provider':
    //   gemini    -> App\Services\Ai\Providers\GeminiProvider     (default)
    //   anthropic -> App\Services\Ai\Providers\AnthropicProvider  (fallback)
    // Never hard-coded elsewhere — swapping provider or model only ever
    // touches the .env value. Gemini is ONLY the LLM; external web
    // discovery stays with Brave (see 'search' below). Gemini API
    // billing/quota is a Google AI Studio / Google Cloud project
    // concern, separate from any consumer Gemini app subscription.
    // See docs/AI_ASSISTANT.md.
    'llm' => [
        'provider' => env('LLM_PROVIDER', 'gemini'),
        'api_key' => env('LLM_API_KEY'),
        'model' => env('LLM_MODEL', 'gemini-2.5-flash'),
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
        // summing mixed currencies. Defaults to the application's
        // business currency (config('app.currency')); override only to
        // aggregate a different single currency.
        'default_currency' => env('COST_TO_SERVE_DEFAULT_CURRENCY', env('APP_DEFAULT_CURRENCY', 'PHP')),
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

        // ── V2.3: Transparent Prospect Lead Scoring ────────────────
        // A deterministic 100-point business-development prioritisation
        // model computed by the application from V2.2 qualification
        // evidence — NEVER by the LLM, NEVER a conversion probability.
        // Same pattern as config('services.business_development'). Edit
        // the weights here to tune; the model is validated on load and
        // falls back to the frozen defaults if it does not total 100 or
        // the bands overlap. See docs/MARKET_INTELLIGENCE.md.
        'scoring' => [
            'model_version' => env('MI_SCORING_VERSION', 'v2.3-default-1'),
            // Seven dimensions; the weights MUST total exactly 100.
            'weights' => [
                'industry_fit' => 20,
                'geography_fit' => 15,
                'online_selling' => 20,
                'physical_product_relevance' => 15,
                'shipping_signals' => 15,
                'digital_activity' => 10,
                'evidence_quality' => 5,
            ],
            // A CEILING applied to the raw score per qualification
            // outcome (spec §14) — never added points. Must be
            // non-increasing from strong_match to insufficient_evidence.
            'outcome_caps' => [
                'strong_match' => 100,
                'possible_match' => 85,
                'weak_match' => 55,
                'insufficient_evidence' => 35,
            ],
            // Priority-band thresholds: HIGH >= high, MEDIUM >= medium,
            // else LOW. 0 < medium < high <= 100.
            'bands' => [
                'high' => env('MI_SCORING_BAND_HIGH', 75),
                'medium' => env('MI_SCORING_BAND_MEDIUM', 50),
            ],
            // Per-user scoring calls allowed per rolling hour (scoring
            // re-runs the bounded V2.2 qualification pipeline).
            'max_scorings_per_hour' => env('MARKET_INTELLIGENCE_MAX_SCORE_PER_HOUR', 12),
        ],

        // ── V2.4: CRM Duplicate Detection ─────────────────────────
        // The ONLY Market Intelligence capability with CRM reach — a
        // narrow, scopeToUser-scoped read of `organizations` identity
        // columns. Deterministic matching only; the policy is validated
        // on load and falls back to frozen defaults. No migration, no
        // new table. See docs/MARKET_INTELLIGENCE.md.
        'duplicate_check' => [
            'policy_version' => env('MI_DUP_POLICY_VERSION', 'v2.4-default-1'),
            // Fuzzy business-name match threshold (Sørensen–Dice over
            // normalised tokens). Exact domain always outweighs this.
            'fuzzy_name_dice_threshold' => 0.85,
            // A name with fewer distinctive (non-generic) tokens than
            // this is treated as generic and needs domain corroboration
            // to become a strong match (spec §13).
            'min_distinctive_name_tokens' => 2,
            // Max CRM matches surfaced per prospect (spec §18).
            'max_candidates_per_prospect' => 5,
            // Max scoped organisations loaded per prospect before
            // matching — prevents an unbounded CRM scan (spec §30).
            'candidate_scan_cap' => 50,
            // Max prospects one duplicate-check call evaluates.
            'max_prospects_per_check' => 10,
            // Per-user duplicate-check calls allowed per rolling hour.
            'max_checks_per_hour' => env('MARKET_INTELLIGENCE_MAX_DUP_PER_HOUR', 12),
        ],

        // ── V2.5: Human-Confirmed CRM Lead Creation ───────────────
        // The AI only ever PREPARES a proposal. The lead is written by
        // the existing V1 LeadService/OrganizationService, and only
        // after an explicit human confirmation on the review page (with
        // a fresh CRM duplicate re-check). See docs/MARKET_INTELLIGENCE.md.
        'lead_creation' => [
            'policy_version' => env('MI_LEAD_PROPOSAL_VERSION', 'v2.5-default-1'),
            // How long a prepared proposal stays confirmable, in hours.
            'proposal_ttl_hours' => 48,
            // Per-user proposal preparations allowed per rolling hour.
            'max_proposals_per_hour' => env('MARKET_INTELLIGENCE_MAX_PROPOSALS_PER_HOUR', 20),
            // The `source` recorded on the created Organization + Lead.
            'default_lead_source' => 'Market Intelligence',
        ],
    ],

];
