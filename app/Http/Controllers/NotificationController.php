<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 11: in-app notifications. Every query here is already scoped to
 * `$request->user()->notifications()` (Laravel's Notifiable relation) —
 * a user can structurally never read or mark another user's
 * notification, since the lookup itself only ever searches their own,
 * exactly like a policy would enforce, without needing a separate
 * Policy class for a framework-owned model.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()->notifications()->paginate(20);

        return view('notifications.index', ['notifications' => $notifications]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->get()->markAsRead();

        return back();
    }
}
