<?php

namespace App\Policies;

use App\Models\ProspectLeadProposal;
use App\Models\User;

/**
 * V2.5: only the user a proposal belongs to may view, confirm, or cancel
 * it — and only Managers / Team Heads can reach Market Intelligence at
 * all. Mirrors WorkflowApprovalPolicy.
 */
class ProspectLeadProposalPolicy
{
    private function mayUseMarketIntelligence(User $user): bool
    {
        return $user->isManager() || $user->isTeamHead();
    }

    public function view(User $user, ProspectLeadProposal $proposal): bool
    {
        return $this->mayUseMarketIntelligence($user) && $proposal->user_id === $user->id;
    }

    public function confirm(User $user, ProspectLeadProposal $proposal): bool
    {
        return $this->view($user, $proposal);
    }

    public function cancel(User $user, ProspectLeadProposal $proposal): bool
    {
        return $this->view($user, $proposal);
    }
}
