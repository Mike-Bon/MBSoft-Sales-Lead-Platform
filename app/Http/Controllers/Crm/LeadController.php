<?php

namespace App\Http\Controllers\Crm;

use App\Enums\ActivityType;
use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LeadController extends Controller
{
    use ScopesCrmQueries;

    public function __construct(private readonly LeadService $leads) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Lead::class);

        $query = $this->scopeToUser(Lead::query()->with(['organization', 'contact', 'owner', 'team']), $request->user());

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }

        if ($teamId = $request->query('team_id')) {
            $query->where('team_id', $teamId);
        }

        if ($ownerId = $request->query('owner_id')) {
            $query->where('owner_id', $ownerId);
        }

        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }

        if ($followUp = $request->query('follow_up')) {
            $this->applyFollowUpFilter($query, $followUp);
        }

        if ($createdFrom = $request->query('created_from')) {
            $query->whereDate('created_at', '>=', $createdFrom);
        }

        if ($createdTo = $request->query('created_to')) {
            $query->whereDate('created_at', '<=', $createdTo);
        }

        $leads = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        return view('crm.leads.index', [
            'leads' => $leads,
            'filters' => $request->only(['status', 'priority', 'team_id', 'owner_id', 'source', 'follow_up', 'created_from', 'created_to']),
            'statuses' => LeadStatus::cases(),
            'priorities' => LeadPriority::cases(),
            'teams' => Team::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Lead::class);

        return view('crm.leads.create', $this->formOptions() + [
            'selectedOrganizationId' => $request->query('organization_id'),
            'selectedContactId' => $request->query('contact_id'),
        ]);
    }

    public function store(StoreLeadRequest $request): RedirectResponse
    {
        $lead = $this->leads->create($request->user(), $request->validated());

        return redirect()->route('crm.leads.show', $lead)->with('status', 'Lead created.');
    }

    public function show(Lead $lead): View
    {
        $this->authorize('view', $lead);

        $lead->load(['organization', 'contact', 'owner', 'team', 'opportunities.owner']);

        // 'communication' is eager-loaded so the timeline (STEP 15 of
        // Phase 6) can show a distinguishing badge/link for activities
        // that represent an actual sent/received message, without an
        // extra query per row.
        $timeline = $lead->activities()->with(['user', 'communication'])->orderByDesc('occurred_at')->get();

        return view('crm.leads.show', [
            'lead' => $lead,
            'timeline' => $timeline,
            'activityTypes' => ActivityType::cases(),
        ]);
    }

    public function edit(Lead $lead): View
    {
        $this->authorize('update', $lead);

        return view('crm.leads.edit', $this->formOptions() + ['lead' => $lead]);
    }

    public function update(UpdateLeadRequest $request, Lead $lead): RedirectResponse
    {
        $this->leads->update($request->user(), $lead, $request->validated());

        return redirect()->route('crm.leads.show', $lead)->with('status', 'Lead updated.');
    }

    public function destroy(Lead $lead): RedirectResponse
    {
        $this->authorize('delete', $lead);

        $this->leads->archive($lead);

        return redirect()->route('crm.leads.index')->with('status', 'Lead archived.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'organizations' => Organization::orderBy('name')->get(),
            'contacts' => Contact::orderBy('last_name')->get(),
            'teams' => Team::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(),
            'statuses' => LeadStatus::cases(),
            'priorities' => LeadPriority::cases(),
        ];
    }

    private function applyFollowUpFilter(Builder $query, string $bucket): void
    {
        $now = Carbon::now();

        match ($bucket) {
            'overdue' => $query->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<', $now->copy()->startOfDay()),
            'due_today' => $query->whereBetween('next_follow_up_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()]),
            'upcoming' => $query->where('next_follow_up_at', '>', $now->copy()->endOfDay()),
            'not_set' => $query->whereNull('next_follow_up_at'),
            default => null,
        };
    }
}
