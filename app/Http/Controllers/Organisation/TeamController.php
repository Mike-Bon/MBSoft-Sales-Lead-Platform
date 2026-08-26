<?php

namespace App\Http\Controllers\Organisation;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignTeamHeadRequest;
use App\Http\Requests\StoreTeamRequest;
use App\Http\Requests\UpdateTeamRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Team management. Authorization for every action is enforced by
 * TeamPolicy (via $this->authorize()) — this controller stays thin and
 * never makes an access decision itself. Query scoping below (e.g. a Team
 * Head only ever loading their own team) is defense in depth, not a
 * substitute for the policy check.
 */
class TeamController extends Controller
{
    public function __construct(private readonly TeamManagementService $teams) {}

    public function index(): View
    {
        $this->authorize('viewAny', Team::class);

        $teams = Team::with('teamHead')->withCount('members')->orderBy('name')->get();

        return view('organisation.teams.index', ['teams' => $teams]);
    }

    public function create(): View
    {
        $this->authorize('create', Team::class);

        return view('organisation.teams.create');
    }

    public function store(StoreTeamRequest $request): RedirectResponse
    {
        $this->teams->createTeam($request->validated());

        return redirect()->route('organisation.teams.index')->with('status', 'Team created.');
    }

    public function show(Team $team): View
    {
        $this->authorize('view', $team);

        $team->load(['teamHead', 'members' => fn ($query) => $query->orderBy('name')]);

        return view('organisation.teams.show', ['team' => $team]);
    }

    public function edit(Team $team): View
    {
        $this->authorize('update', $team);

        // Head candidates: anyone already on this team, plus anyone with
        // no team yet. The Manager is never a valid head candidate — a
        // Manager has organisation-wide visibility, not team membership.
        $headCandidates = User::where(function ($query) use ($team) {
            $query->where('team_id', $team->id)->orWhereNull('team_id');
        })
            ->where('role', '!=', UserRole::Manager)
            ->orderBy('name')
            ->get();

        return view('organisation.teams.edit', [
            'team' => $team,
            'headCandidates' => $headCandidates,
        ]);
    }

    public function update(UpdateTeamRequest $request, Team $team): RedirectResponse
    {
        $this->teams->updateTeam($team, $request->validated());

        return redirect()->route('organisation.teams.index')->with('status', 'Team updated.');
    }

    public function assignHead(AssignTeamHeadRequest $request, Team $team): RedirectResponse
    {
        $newHead = User::findOrFail($request->validated('team_head_id'));

        $this->teams->assignTeamHead($team, $newHead);

        return redirect()->route('organisation.teams.index')->with('status', 'Team Head assigned.');
    }
}
