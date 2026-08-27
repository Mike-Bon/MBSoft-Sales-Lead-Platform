<?php

namespace App\Http\Controllers;

use App\Services\CostToServe\AccountEconomicsService;
use App\Support\PeriodSelection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Phase 12: the dedicated Cost-to-Serve page (STEP 21 — a separate page
 * rather than overloading the main Dashboard, summary first, detail on
 * demand). Manager/Team-Head only — commercial economics, matching
 * AgentIdentifier::CostToServe's own eligibility rule (reused, not
 * duplicated as a separate check).
 *
 * Every figure on this page comes from AccountEconomicsService — the
 * exact same aggregation the AI tools use — so the page and the agent
 * can never disagree with each other.
 */
class CostToServeController extends Controller
{
    public function __construct(private readonly AccountEconomicsService $economics) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isManager() || $user->isTeamHead(), 403);

        $period = PeriodSelection::fromRequest($request);
        $currency = (string) config('services.cost_to_serve.default_currency', 'USD');
        $maxAccounts = (int) config('services.cost_to_serve.max_accounts_per_query', 20);

        $lengthInDays = $period->start->diffInDays($period->end);
        $previousEnd = $period->start->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays($lengthInDays);

        $summary = $this->economics->organisationSummary($user, $period->start, $period->end, $currency);
        $topAccounts = $this->economics->topAccountsByRevenue($user, $period->start, $period->end, $currency, 10);
        $exceptions = $this->economics->identifyExceptions($user, $period->start, $period->end, $previousStart, $previousEnd, $currency, null, $maxAccounts);

        return view('cost-to-serve.index', [
            'period' => $period,
            'currency' => $currency,
            'summary' => $summary,
            'topAccounts' => $topAccounts,
            'exceptions' => $exceptions,
        ]);
    }
}
