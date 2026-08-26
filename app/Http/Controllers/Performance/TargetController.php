<?php

namespace App\Http\Controllers\Performance;

use App\Enums\TargetPeriodType;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTargetRequest;
use App\Http\Requests\UpdateTargetRequest;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use App\Services\PerformanceService;
use App\Services\TargetService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TargetController extends Controller
{
    public function __construct(
        private readonly TargetService $targets,
        private readonly PerformanceService $performance,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Target::class);

        $targets = $this->scopeToUser(Target::query()->with(['owner', 'team']), $request->user())
            ->orderByDesc('period_start')
            ->paginate(15)
            ->withQueryString();

        return view('performance.targets.index', ['targets' => $targets]);
    }

    public function create(): View
    {
        $this->authorize('create', Target::class);

        return view('performance.targets.create', $this->formOptions());
    }

    public function store(StoreTargetRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['target_type'] = TargetType::from($data['target_type']);
        $data['period_type'] = TargetPeriodType::from($data['period_type']);
        $data['status'] = isset($data['status']) ? TargetStatus::from($data['status']) : TargetStatus::Active;

        $target = $this->targets->create($data);

        return redirect()->route('performance.targets.show', $target)->with('status', 'Target created.');
    }

    public function show(Target $target): View
    {
        $this->authorize('view', $target);

        return view('performance.targets.show', [
            'target' => $target,
            'snapshot' => $this->performance->forTarget($target),
        ]);
    }

    public function edit(Target $target): View
    {
        $this->authorize('update', $target);

        return view('performance.targets.edit', $this->formOptions() + ['target' => $target]);
    }

    public function update(UpdateTargetRequest $request, Target $target): RedirectResponse
    {
        $data = $request->validated();
        $data['target_type'] = TargetType::from($data['target_type']);
        $data['period_type'] = TargetPeriodType::from($data['period_type']);
        $data['status'] = isset($data['status']) ? TargetStatus::from($data['status']) : $target->status;

        $this->targets->update($target, $data);

        return redirect()->route('performance.targets.show', $target)->with('status', 'Target updated.');
    }

    public function destroy(Target $target): RedirectResponse
    {
        $this->authorize('update', $target);

        $this->targets->deactivate($target);

        return redirect()->route('performance.targets.index')->with('status', 'Target deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'types' => TargetType::cases(),
            'periodTypes' => TargetPeriodType::cases(),
            'statuses' => TargetStatus::cases(),
            'managers' => User::where('role', UserRole::Manager)->orderBy('name')->get(),
            'teams' => Team::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(),
        ];
    }

    /**
     * Mirrors TargetPolicy::view() as a query scope: Manager sees
     * everything; a Team Head/Member sees their own team's Team target
     * and their own Individual target; a Team Head additionally sees
     * their team members' Individual targets. Query scoping here is
     * defense in depth alongside (never a replacement for) the policy
     * check on show/edit/update.
     */
    private function scopeToUser(Builder $query, User $user): Builder
    {
        if ($user->isManager()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user) {
            $query->where(function (Builder $q) use ($user) {
                $q->where('target_type', TargetType::Team->value)->where('team_id', $user->team_id);
            })->orWhere(function (Builder $q) use ($user) {
                $q->where('target_type', TargetType::Individual->value)->where('owner_id', $user->id);
            });

            if ($user->isTeamHead()) {
                $query->orWhere(function (Builder $q) use ($user) {
                    $q->where('target_type', TargetType::Individual->value)->where('team_id', $user->team_id);
                });
            }
        });
    }
}
