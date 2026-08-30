<?php

namespace App\Services\Ai;

use App\Enums\AgentIdentifier;

/**
 * STEP 16/18/58: decides WHICH agent should handle a free-text request.
 * Deliberately a plain deterministic keyword classifier, not an LLM
 * call — routing a message to the wrong-but-still-authorized agent
 * costs a slightly less on-topic answer; spending a whole extra AI
 * round-trip just to decide which agent to ask would violate STEP 58's
 * cost-control instruction for no real safety benefit, since (STEP 18)
 * "routing is not security" — authorization is enforced identically by
 * every agent's own tools regardless of which one is picked.
 *
 * Communication intent is checked first: "draft a follow-up about the
 * stalled ABC opportunity" mentions sales vocabulary but the user
 * clearly wants the agent that can actually produce a draft.
 */
final class AgentRouter
{
    private const COMMUNICATION_KEYWORDS = [
        'draft', 'email', 'e-mail', 'whatsapp', 'message', 'send', 'follow up', 'follow-up',
        'contact him', 'contact her', 'contact them', 'reach out', 'write to', 'text ',
    ];

    private const PERFORMANCE_KEYWORDS = [
        'performance', 'target', 'achievement', 'quota', 'pace', 'behind', 'on track',
        'coverage', 'run rate', 'run-rate', 'gap', 'exceed', 'kpi',
    ];

    private const SALES_KEYWORDS = [
        'pipeline', 'opportunit', 'lead', 'deal', 'prospect', 'account', 'prioriti', 'stalled', 'stale',
    ];

    /**
     * Phase 12: checked before Performance/Sales — "cost to serve" and
     * "contribution" would otherwise also match SALES_KEYWORDS'
     * "account", landing on the wrong agent.
     */
    private const COST_TO_SERVE_KEYWORDS = [
        'cost to serve', 'cost-to-serve', 'contribution', 'expensive to serve',
        'economic yield', 'profitability', 'revenue per deal', 'arpu',
    ];

    /**
     * Phase 13: deliberately specific analysis phrasing — never a bare
     * "lead"/"follow-up"/"account" (those stay with Sales/Communication).
     * Checked before Communication so "which leads need follow-up?"
     * (analysis) lands on Business Development, while "draft a follow-up"
     * (a drafting request) still falls through to Communication because
     * none of these phrases match it.
     */
    private const BUSINESS_DEVELOPMENT_KEYWORDS = [
        'prioriti', 'stale lead', 'going cold', 'gone cold', 'cold lead',
        'call plan', 'discovery question', 'discovery call',
        'account plan', 'account summary', 'analyze this account', 'analyse this account',
        'summarize this account', 'summarise this account', 'summarize the account', 'summarise the account',
        'at risk', 'at-risk',
        'missing information', 'incomplete information', 'what information is missing', 'what am i missing',
        'follow-up gap', 'follow up gap', 'who needs follow', 'needs follow-up', 'need follow-up',
        'needs a follow-up', 'overdue follow',
        'next best action', 'next action', 'what should my next',
        'expansion potential', 'expansion opportunit',
        'which prospects', 'prospects to pursue', 'pursue first', 'which leads should i',
    ];

    /**
     * V2.1 + V2.2: external prospect discovery and qualification.
     * Deliberately specific phrasing about finding / evaluating NEW
     * businesses "out there" — checked first, and it never matches an
     * internal-CRM question (which refers to "my leads", "our pipeline",
     * a named account, etc.).
     */
    private const MARKET_INTELLIGENCE_KEYWORDS = [
        // V2.1 — discovery
        'find businesses', 'find companies', 'find me businesses', 'find me companies',
        'businesses in ', 'companies in ', 'businesses selling', 'companies selling',
        'businesses that sell', 'companies that sell', 'businesses that appear', 'companies that appear',
        'online sellers', 'find online', 'sellers of', 'find sellers',
        'find potential customer', 'find potential client', 'potential courier customer',
        'discover prospect', 'prospect discovery', 'market research', 'market intelligence',
        'find target companies', 'find new businesses', 'find shops', 'find stores',
        // V2.2 — qualification
        'qualify prospect', 'qualify these', 'qualify the ', 'qualify candidates',
        'qualify the businesses', 'qualify those', 'prospect qualification',
        'qualification of prospects', 'do these businesses match', 'does this prospect match',
        'do these prospects match', 'which of these are a strong match', 'which of these match',
        'against my criteria', 'against these criteria', 'against the criteria',
        'hard criteria', 'evidence-based qualification', 'how well do these match',
    ];

    public function route(string $message): AgentIdentifier
    {
        $lower = strtolower($message);

        if ($this->matchesAny($lower, self::MARKET_INTELLIGENCE_KEYWORDS)) {
            return AgentIdentifier::MarketIntelligence;
        }

        if ($this->matchesAny($lower, self::BUSINESS_DEVELOPMENT_KEYWORDS)) {
            return AgentIdentifier::BusinessDevelopment;
        }

        if ($this->matchesAny($lower, self::COMMUNICATION_KEYWORDS)) {
            return AgentIdentifier::Communication;
        }

        if ($this->matchesAny($lower, self::COST_TO_SERVE_KEYWORDS)) {
            return AgentIdentifier::CostToServe;
        }

        $matchesPerformance = $this->matchesAny($lower, self::PERFORMANCE_KEYWORDS);
        $matchesSales = $this->matchesAny($lower, self::SALES_KEYWORDS);

        if ($matchesPerformance && ! $matchesSales) {
            return AgentIdentifier::Performance;
        }

        if ($matchesSales) {
            return AgentIdentifier::Sales;
        }

        // Ambiguous/default: Sales Intelligence is the closest analog to
        // Phase 7's original general CRM assistant (STEP 45 backward
        // compatibility) — a plain "what should I look at today?"
        // lands here.
        return AgentIdentifier::Sales;
    }

    /**
     * STEP 20/37: a genuinely cross-domain request — the application-
     * controlled Management Review sequence (Performance Agent then
     * Sales Agent) is used instead of a single agent, but ONLY when the
     * message clearly needs both, never by default (STEP 58 cost
     * control).
     */
    public function isManagementReviewRequest(string $message): bool
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'management review') || str_contains($lower, 'sales review')) {
            return true;
        }

        return $this->matchesAny($lower, self::PERFORMANCE_KEYWORDS) && $this->matchesAny($lower, self::SALES_KEYWORDS);
    }

    /**
     * @param  list<string>  $keywords
     */
    private function matchesAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
