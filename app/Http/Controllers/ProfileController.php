<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A lightweight "who am I / what can I do" overview: current role, current
 * team, and the management links this user is actually authorized for.
 * This is distinct from Phase 1's /settings/profile, which edits
 * name/email/password and is unchanged.
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $user->loadMissing(['team', 'headedTeam']);

        return view('organisation.profile', ['profileUser' => $user]);
    }
}
