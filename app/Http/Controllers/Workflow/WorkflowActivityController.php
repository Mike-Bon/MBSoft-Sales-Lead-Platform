<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Models\WorkflowExecution;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * STEP 29/45: "what workflow ran, when, why, what it found, what it
 * recommends" — the plain audit view. Every execution shown here
 * belongs to the current user's own scope (WorkflowExecutionPolicy).
 */
class WorkflowActivityController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', WorkflowExecution::class);

        $executions = WorkflowExecution::where('user_id', $request->user()->id)
            ->with('approvals')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('workflows.index', ['executions' => $executions]);
    }

    public function show(WorkflowExecution $workflowExecution): View
    {
        $this->authorize('view', $workflowExecution);

        $workflowExecution->load(['approvals', 'agentInteraction', 'scopeTeam']);

        return view('workflows.show', ['execution' => $workflowExecution]);
    }
}
