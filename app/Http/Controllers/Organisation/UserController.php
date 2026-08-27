<?php

namespace App\Http\Controllers\Organisation;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Manager-only user management. Authorization for every action is enforced
 * by UserPolicy (via each Form Request's authorize() and the `can:`
 * middleware on these routes) — this controller stays thin and never makes
 * an access decision itself.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserManagementService $users) {}

    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        $users = User::with('team')->orderBy('name')->get();

        return view('organisation.users.index', ['users' => $users]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('organisation.users.create', [
            'roles' => UserRole::cases(),
            'teams' => Team::orderBy('name')->get(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['role'] = UserRole::from($data['role']);

        $this->users->createUser($request->user(), $data);

        return redirect()->route('organisation.users.index')->with('status', 'User created.');
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('organisation.users.edit', [
            'targetUser' => $user,
            'roles' => UserRole::cases(),
            'teams' => Team::orderBy('name')->get(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $data['role'] = UserRole::from($data['role']);

        $this->users->updateUserRoleAndTeam($request->user(), $user, $data);

        return redirect()->route('organisation.users.index')->with('status', 'User updated.');
    }
}
