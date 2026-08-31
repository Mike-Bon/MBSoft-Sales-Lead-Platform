<?php

namespace App\Http\Controllers\MarketIntelligence;

use App\Enums\ProspectProposalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\MarketIntelligence\ConfirmProspectLeadRequest;
use App\Models\ProspectLeadProposal;
use App\Services\MarketIntelligence\ProspectLeadCreationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * V2.5: the human review + confirmation surface for a prospect → CRM
 * lead proposal. The GET page shows what would be created; the POST
 * confirm is the ONLY path that writes a lead, and it goes through
 * ProspectLeadCreationService (fingerprint check, eligibility gate,
 * fresh duplicate re-check, existing V1 LeadService).
 *
 * Every action separately enforces ProspectLeadProposalPolicy, exactly
 * like every other controller in this application.
 */
class ProspectLeadProposalController extends Controller
{
    public function __construct(private readonly ProspectLeadCreationService $creation) {}

    public function show(Request $request, ProspectLeadProposal $proposal): View
    {
        $this->authorize('view', $proposal);

        return view('market-intelligence.prospect-proposals.show', [
            'proposal' => $proposal,
            'expired' => $proposal->isExpired(),
        ]);
    }

    public function confirm(ConfirmProspectLeadRequest $request, ProspectLeadProposal $proposal): RedirectResponse
    {
        $this->authorize('confirm', $proposal);

        $result = $this->creation->confirmAndCreate($request->user(), $proposal, $request->validatedFields());

        if (($result['status'] ?? null) === 'created') {
            return redirect()->route('crm.leads.show', $result['lead_id'])
                ->with('status', 'Lead created from Market Intelligence prospect research.');
        }

        if (($result['status'] ?? null) === 'already_created') {
            return redirect()->route('crm.leads.show', $result['lead_id'])
                ->with('status', $result['message']);
        }

        return redirect()->route('market-intelligence.prospect-proposals.show', $proposal)
            ->with('proposal_error', $result['message'] ?? 'The lead could not be created. Review the proposal again.');
    }

    public function cancel(Request $request, ProspectLeadProposal $proposal): RedirectResponse
    {
        $this->authorize('cancel', $proposal);

        if ($proposal->status === ProspectProposalStatus::Pending) {
            $proposal->forceFill([
                'status' => ProspectProposalStatus::Cancelled->value,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ])->save();
        }

        return redirect()->route('assistant.show')->with('status', 'Prospect proposal cancelled. Nothing was created.');
    }
}
