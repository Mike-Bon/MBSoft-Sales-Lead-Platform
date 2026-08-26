<?php

namespace App\Http\Controllers\Workflow;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\WorkflowApproval;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * STEP 20/29: the approval queue. "Approve & Send"/"Edit" both route
 * through the existing, already-tested composer (see
 * CommunicationController::resolveContext()'s workflow_approval_id
 * handling and CommunicationService::resolveWorkflowApproval()'s
 * revalidation) — this controller only handles listing and the
 * Reject action, which has no external side effect and needs no
 * composer round trip.
 */
class ApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', WorkflowApproval::class);

        $approvals = WorkflowApproval::where('user_id', $request->user()->id)
            ->where('status', ApprovalStatus::Pending)
            ->with(['workflowExecution', 'contact', 'lead', 'opportunity', 'organization'])
            ->orderBy('expires_at')
            ->get();

        $decided = WorkflowApproval::where('user_id', $request->user()->id)
            ->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Rejected])
            ->orderByDesc('decided_at')
            ->limit(10)
            ->get();

        return view('workflows.approvals.index', ['approvals' => $approvals, 'decided' => $decided]);
    }

    public function reject(Request $request, WorkflowApproval $approval): RedirectResponse
    {
        $this->authorize('update', $approval);

        if ($approval->isActionable()) {
            $approval->status = ApprovalStatus::Rejected;
            $approval->decided_at = now();
            $approval->decided_by = $request->user()->id;
            $approval->save();
        }

        return redirect()->route('workflows.approvals.index')->with('status', 'Proposal rejected. Nothing was sent.');
    }
}
