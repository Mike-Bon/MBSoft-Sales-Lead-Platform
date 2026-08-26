<?php

namespace App\Http\Controllers\Communication;

use App\Enums\CommunicationChannel;
use App\Enums\RecordStatus;
use App\Enums\WhatsAppNumberStatus;
use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\SendEmailRequest;
use App\Http\Requests\Communication\SendWhatsAppRequest;
use App\Models\Communication;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\WhatsAppBusinessNumber;
use App\Models\WorkflowApproval;
use App\Services\Communication\CommunicationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * STEP 16 (Message Composer) + STEP 25 (Auditability). Every send here
 * is a human clicking "Send" on a form after an explicit confirmation
 * checkbox (STEP 18: manual send only) — nothing in this controller is
 * ever invoked automatically, on a schedule, or from generated content.
 */
class CommunicationController extends Controller
{
    use ScopesCrmQueries;

    public function __construct(private readonly CommunicationService $communications) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Communication::class);

        $query = $this->scopeToUser(
            Communication::query()->with(['user', 'contact', 'lead', 'opportunity', 'organization']),
            $request->user(),
        );

        if ($channel = $request->query('channel')) {
            $query->where('channel', $channel);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $communications = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        return view('communications.index', [
            'communications' => $communications,
            'filters' => $request->only(['channel', 'status']),
        ]);
    }

    public function show(Communication $communication): View
    {
        $this->authorize('view', $communication);

        $communication->load(['user', 'team', 'contact', 'lead', 'opportunity', 'organization', 'emailAccount', 'whatsAppNumber', 'template']);

        return view('communications.show', ['communication' => $communication]);
    }

    public function composeEmail(Request $request): View
    {
        $this->authorize('create', Communication::class);

        $account = $request->user()->emailAccount;

        return view('communications.compose-email', [
            'account' => $account,
            'templates' => MessageTemplate::query()
                ->where('channel', CommunicationChannel::Email)
                ->where('status', RecordStatus::Active)
                ->get()
                ->filter(fn (MessageTemplate $t) => $request->user()->isManager() || $t->team_id === null || $t->team_id === $request->user()->team_id),
            'context' => $this->resolveContext($request),
        ]);
    }

    public function sendEmail(SendEmailRequest $request): RedirectResponse
    {
        $communication = $this->communications->sendEmail($request->user(), $request->validated());

        return redirect()->route('communications.show', $communication)
            ->with('status', 'Email queued for sending.');
    }

    public function composeWhatsApp(Request $request): View
    {
        $this->authorize('create', Communication::class);

        $numbers = WhatsAppBusinessNumber::query()
            ->where('status', WhatsAppNumberStatus::Connected)
            ->get()
            ->filter(fn (WhatsAppBusinessNumber $n) => $request->user()->isManager() || $n->team_id === null || $n->team_id === $request->user()->team_id);

        return view('communications.compose-whatsapp', [
            'numbers' => $numbers,
            'templates' => MessageTemplate::query()
                ->where('channel', CommunicationChannel::WhatsApp)
                ->where('status', RecordStatus::Active)
                ->get()
                ->filter(fn (MessageTemplate $t) => $request->user()->isManager() || $t->team_id === null || $t->team_id === $request->user()->team_id),
            'context' => $this->resolveContext($request),
        ]);
    }

    public function sendWhatsApp(SendWhatsAppRequest $request): RedirectResponse
    {
        $communication = $this->communications->sendWhatsApp($request->user(), $request->validated());

        return redirect()->route('communications.show', $communication)
            ->with('status', 'WhatsApp message queued for sending.');
    }

    /**
     * @return array{organization_id: ?int, contact_id: ?int, lead_id: ?int, opportunity_id: ?int, recipient: ?string, recipient_phone: ?string, subject: ?string, body: ?string}
     */
    private function resolveContext(Request $request): array
    {
        $contactId = $request->query('contact_id');
        $leadId = $request->query('lead_id');
        $opportunityId = $request->query('opportunity_id');
        $organizationId = $request->query('organization_id');

        // Phase 8: a workflow-produced approval is the single source of
        // truth for prefill when present — its own CRM references take
        // priority over any other query param, so the composer can
        // never show a mismatched combination of "approval X's body"
        // and "a different record's contact/organization".
        $approval = $this->resolveApprovalForPrefill($request);

        if ($approval) {
            $contactId = $approval->contact_id ?? $contactId;
            $leadId = $approval->lead_id ?? $leadId;
            $opportunityId = $approval->opportunity_id ?? $opportunityId;
            $organizationId = $approval->organization_id ?? $organizationId;
        }

        $contact = $contactId ? Contact::find($contactId) : null;
        $contact ??= $leadId ? Lead::find($leadId)?->contact : null;
        $contact ??= $opportunityId ? Opportunity::find($opportunityId)?->contact : null;

        $organization = $organizationId ? Organization::find($organizationId) : null;
        $organization ??= $contact?->organization;

        return [
            'organization_id' => $organization?->id,
            'contact_id' => $contact?->id,
            'lead_id' => $leadId ? (int) $leadId : null,
            'opportunity_id' => $opportunityId ? (int) $opportunityId : null,
            // A prefilled recipient/subject/body (e.g. from the AI
            // assistant's draft_email/draft_whatsapp tool, or a Phase 8
            // workflow approval) always takes priority over a
            // contact-derived default: the human is reviewing that
            // specific drafted content, not a fresh blank composer.
            'recipient' => $approval?->recipient ?? $request->query('recipient', $contact?->email),
            'recipient_phone' => $approval?->recipient ?? $request->query('recipient', $contact?->mobile ?? $contact?->phone),
            'subject' => $approval?->subject ?? $request->query('subject'),
            'body' => $approval?->body ?? $request->query('body'),
            'workflow_approval_id' => $approval?->id,
            'whatsapp_number_id' => $approval?->whatsapp_number_id,
        ];
    }

    /**
     * Only ever returns an approval the authenticated user actually owns
     * and can still act on — a guessed/foreign/stale id is treated
     * exactly as if none was supplied, never exposed here even to
     * prefill a form (the real enforcement is
     * CommunicationService::resolveWorkflowApproval at send time; this
     * is defense in depth against confusing/leaking prefill data).
     */
    private function resolveApprovalForPrefill(Request $request): ?WorkflowApproval
    {
        $id = $request->query('workflow_approval_id');

        if (! $id) {
            return null;
        }

        $approval = WorkflowApproval::find($id);

        if (! $approval || $approval->user_id !== $request->user()->id || ! $approval->isActionable()) {
            return null;
        }

        return $approval;
    }
}
