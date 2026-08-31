<?php

namespace App\Http\Controllers;

use App\Services\CostToServe\AccountEconomicsService;
use App\Services\CostToServe\CostToServeAccessService;
use App\Support\Money;
use App\Support\PeriodSelection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase 12: the dedicated Cost-to-Serve page (STEP 21 — a separate page
 * rather than overloading the main Dashboard, summary first, detail on
 * demand).
 *
 * Phase 12A: role and the global feature switch are deliberately
 * checked as two separate things (index() vs. settings()/update()):
 * only a Manager may reach either, but the analysis page (index) also
 * requires the switch to be on, while the settings page does not —
 * "feature access ≠ feature administration" (STEP 4). Every figure on
 * the analysis page comes from AccountEconomicsService — the exact
 * same aggregation the AI tools use — so the page and the agent can
 * never disagree with each other.
 */
class CostToServeController extends Controller
{
    public function __construct(
        private readonly AccountEconomicsService $economics,
        private readonly CostToServeAccessService $access,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($this->access->isRoleAuthorized($user), 403);

        if (! $this->access->isEnabled()) {
            return view('cost-to-serve.disabled');
        }

        $period = PeriodSelection::fromRequest($request);
        $currency = (string) config('services.cost_to_serve.default_currency', Money::defaultCurrency());
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

    /**
     * STEP 4/12: the administrative control — reachable by a Manager
     * regardless of the switch's current state, so turning the feature
     * off can never lock the Manager out of turning it back on.
     */
    public function settings(Request $request): View
    {
        abort_unless($this->access->isRoleAuthorized($request->user()), 403);

        return view('cost-to-serve.settings', ['enabled' => $this->access->isEnabled()]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->access->isRoleAuthorized($user), 403);

        $request->validate(['enabled' => ['required', 'boolean']]);

        if ($request->boolean('enabled')) {
            $this->access->enable($user);
            $status = 'Cost-to-Serve Intelligence enabled.';
        } else {
            $this->access->disable($user);
            $status = 'Cost-to-Serve Intelligence disabled.';
        }

        return redirect()->route('cost-to-serve.settings')->with('status', $status);
    }
}
