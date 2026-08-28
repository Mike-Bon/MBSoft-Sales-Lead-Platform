<?php

namespace App\Http\Controllers;

use App\Services\BusinessDevelopment\LeadIntelligenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Phase 13: the dedicated Business Development page — "what should I
 * work on?", scannable, summary first. Manager and Team Head only
 * (prospecting/account-development is management- and team-lead-level
 * work), matching AgentIdentifier::BusinessDevelopment's own
 * eligibility rule.
 *
 * Every figure comes from LeadIntelligenceService — the exact same
 * transparent scoring the Business Development agent's tools use — so
 * the page and the agent can never disagree. The page itself is
 * strictly read-only: every row links to the real lead/opportunity for
 * the human to act there, through the normal authorisation and audit.
 */
class BusinessDevelopmentController extends Controller
{
    public function __construct(private readonly LeadIntelligenceService $intelligence) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isManager() || $user->isTeamHead(), 403);

        return view('business-development.index', [
            'priorities' => $this->intelligence->prioritizedLeads($user, null, 10)['leads'],
            'followUpGaps' => $this->intelligence->followUpGaps($user, null, 10)['gaps'],
            'atRisk' => $this->intelligence->atRiskOpportunities($user, null, 10)['opportunities'],
        ]);
    }
}
