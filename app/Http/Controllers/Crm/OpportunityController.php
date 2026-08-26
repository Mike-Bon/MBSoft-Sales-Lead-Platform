<?php

namespace App\Http\Controllers\Crm;

use App\Enums\OpportunityStage;
use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOpportunityRequest;
use App\Http\Requests\UpdateOpportunityRequest;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\OpportunityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OpportunityController extends Controller
{
    use ScopesCrmQueries;

    public function __construct(private readonly OpportunityService $opportunities) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Opportunity::class);

        $query = $this->scopeToUser(Opportunity::query()->with(['organization', 'contact', 'lead', 'owner', 'team']), $request->user());

        if ($stage = $request->query('stage')) {
            $query->where('stage', $stage);
        }

        if ($ownerId = $request->query('owner_id')) {
            $query->where('owner_id', $ownerId);
        }

        if ($teamId = $request->query('team_id')) {
            $query->where('team_id', $teamId);
        }

        if ($closeFrom = $request->query('close_from')) {
            $query->whereDate('expected_close_date', '>=', $closeFrom);
        }

        if ($closeTo = $request->query('close_to')) {
            $query->whereDate('expected_close_date', '<=', $closeTo);
        }

        $opportunities = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        return view('crm.opportunities.index', [
            'opportunities' => $opportunities,
            'filters' => $request->only(['stage', 'owner_id', 'team_id', 'close_from', 'close_to']),
            'stages' => OpportunityStage::cases(),
            'teams' => Team::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Opportunity::class);

        return view('crm.opportunities.create', $this->formOptions() + [
            'selectedLeadId' => $request->query('lead_id'),
        ]);
    }

    public function store(StoreOpportunityRequest $request): RedirectResponse
    {
        $opportunity = $this->opportunities->create($request->user(), $request->validated());

        return redirect()->route('crm.opportunities.show', $opportunity)->with('status', 'Opportunity created.');
    }

    public function show(Opportunity $opportunity): View
    {
        $this->authorize('view', $opportunity);

        $opportunity->load(['organization', 'contact', 'lead', 'owner', 'team']);

        $timeline = $opportunity->activities()->with('user')->orderByDesc('occurred_at')->get();

        return view('crm.opportunities.show', ['opportunity' => $opportunity, 'timeline' => $timeline]);
    }

    public function edit(Opportunity $opportunity): View
    {
        $this->authorize('update', $opportunity);

        return view('crm.opportunities.edit', $this->formOptions() + ['opportunity' => $opportunity]);
    }

    public function update(UpdateOpportunityRequest $request, Opportunity $opportunity): RedirectResponse
    {
        $this->opportunities->update($request->user(), $opportunity, $request->validated());

        return redirect()->route('crm.opportunities.show', $opportunity)->with('status', 'Opportunity updated.');
    }

    public function destroy(Opportunity $opportunity): RedirectResponse
    {
        $this->authorize('delete', $opportunity);

        $this->opportunities->archive($opportunity);

        return redirect()->route('crm.opportunities.index')->with('status', 'Opportunity archived.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'organizations' => Organization::orderBy('name')->get(),
            'contacts' => Contact::orderBy('last_name')->get(),
            'leads' => Lead::orderBy('created_at', 'desc')->get(),
            'teams' => Team::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(),
            'stages' => OpportunityStage::cases(),
        ];
    }
}
