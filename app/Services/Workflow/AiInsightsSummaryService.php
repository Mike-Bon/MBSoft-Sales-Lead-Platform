<?php

namespace App\Services\Workflow;

use App\Enums\ApprovalStatus;
use App\Enums\WorkflowType;
use App\Models\User;
use App\Models\WorkflowApproval;
use App\Models\WorkflowExecution;

/**
 * STEP 53/54/55: the small "AI Insights" summary shown on each
 * dashboard — the latest run of each workflow type for this user's own
 * scope, plus their pending-approval count. Deliberately minimal: this
 * is not a second reporting engine, just a thin read over
 * WorkflowExecution/WorkflowApproval, both already scoped to the
 * authenticated user by definition (STEP 23).
 */
class AiInsightsSummaryService
{
    /**
     * @return array{latest: array<string, ?WorkflowExecution>, pending_approvals: int}
     */
    public function forUser(User $user): array
    {
        $latest = [];

        foreach (WorkflowType::cases() as $type) {
            $latest[$type->value] = WorkflowExecution::where('user_id', $user->id)
                ->where('workflow', $type->value)
                ->latest('created_at')
                ->first();
        }

        return [
            'latest' => $latest,
            'pending_approvals' => WorkflowApproval::where('user_id', $user->id)
                ->where('status', ApprovalStatus::Pending)
                ->count(),
        ];
    }
}
