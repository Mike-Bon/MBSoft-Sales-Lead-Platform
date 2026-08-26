<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\OrganizationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    use ScopesCrmQueries;

    public function __construct(private readonly OrganizationService $organizations) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Organization::class);

        $query = $this->scopeToUser(Organization::query()->with(['owner', 'team']), $request->user());

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($industry = $request->query('industry')) {
            $query->where('industry', $industry);
        }

        if ($ownerId = $request->query('owner_id')) {
            $query->where('owner_id', $ownerId);
        }

        if ($teamId = $request->query('team_id')) {
            $query->where('team_id', $teamId);
        }

        $organizations = $query->orderBy('name')->paginate(15)->withQueryString();

        return view('crm.organizations.index', [
            'organizations' => $organizations,
            'filters' => $request->only(['search', 'industry', 'owner_id', 'team_id']),
            'industries' => Organization::query()->whereNotNull('industry')->distinct()->orderBy('industry')->pluck('industry'),
            'teams' => Team::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Organization::class);

        return view('crm.organizations.create', [
            'teams' => Team::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(),
        ]);
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        $organization = $this->organizations->create($request->user(), $request->validated());

        return redirect()->route('crm.organizations.show', $organization)->with('status', 'Organization created.');
    }

    public function show(Organization $organization): View
    {
        $this->authorize('view', $organization);

        $organization->load(['owner', 'team', 'contacts', 'leads.owner', 'opportunities.owner']);

        return view('crm.organizations.show', ['organization' => $organization]);
    }

    public function edit(Organization $organization): View
    {
        $this->authorize('update', $organization);

        return view('crm.organizations.edit', [
            'organization' => $organization,
            'teams' => Team::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(),
        ]);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): RedirectResponse
    {
        $this->organizations->update($request->user(), $organization, $request->validated());

        return redirect()->route('crm.organizations.show', $organization)->with('status', 'Organization updated.');
    }

    public function destroy(Organization $organization): RedirectResponse
    {
        $this->authorize('delete', $organization);

        $this->organizations->archive($organization);

        return redirect()->route('crm.organizations.index')->with('status', 'Organization archived.');
    }
}
