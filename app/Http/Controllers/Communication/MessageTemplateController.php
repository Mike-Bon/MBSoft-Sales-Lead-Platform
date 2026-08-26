<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreMessageTemplateRequest;
use App\Http\Requests\Communication\UpdateMessageTemplateRequest;
use App\Models\MessageTemplate;
use App\Models\Team;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * STEP 17: template CRUD. Rendering a template into a real message is
 * TemplateRenderer's job (invoked from CommunicationService), never done
 * here — this controller only manages the stored name/channel/subject/
 * body/status.
 */
class MessageTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', MessageTemplate::class);

        $templates = MessageTemplate::query()
            ->with(['createdBy', 'team'])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (MessageTemplate $t) => $request->user()->isManager() || $t->team_id === null || $t->team_id === $request->user()->team_id);

        return view('communications.templates.index', ['templates' => $templates]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', MessageTemplate::class);

        return view('communications.templates.create', [
            'teams' => $request->user()->isManager() ? Team::orderBy('name')->get() : collect(),
        ]);
    }

    public function store(StoreMessageTemplateRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();

        $template = new MessageTemplate([
            'name' => $data['name'],
            'channel' => $data['channel'],
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
        ]);
        $template->created_by = $actor->id;
        // A Manager may optionally scope the template to one team, or
        // leave it organisation-wide (null). Every other role's template
        // is always scoped to their own team — never taken from request
        // input, so a Team Member can never publish an org-wide template.
        $template->team_id = $actor->isManager() ? ($data['team_id'] ?? null) : $actor->team_id;
        $template->save();

        return redirect()->route('communications.templates.index')->with('status', 'Template created.');
    }

    public function edit(MessageTemplate $messageTemplate): View
    {
        $this->authorize('update', $messageTemplate);

        return view('communications.templates.edit', ['template' => $messageTemplate]);
    }

    public function update(UpdateMessageTemplateRequest $request, MessageTemplate $messageTemplate): RedirectResponse
    {
        $messageTemplate->fill($request->validated())->save();

        return redirect()->route('communications.templates.index')->with('status', 'Template updated.');
    }

    public function destroy(MessageTemplate $messageTemplate): RedirectResponse
    {
        $this->authorize('delete', $messageTemplate);

        $messageTemplate->delete();

        return redirect()->route('communications.templates.index')->with('status', 'Template removed.');
    }
}
