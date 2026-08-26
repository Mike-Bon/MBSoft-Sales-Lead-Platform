<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactRequest;
use App\Http\Requests\UpdateContactRequest;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\ContactService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    use ScopesCrmQueries;

    public function __construct(private readonly ContactService $contacts) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Contact::class);

        $query = $this->scopeToUser(Contact::query()->with(['organization', 'owner', 'team']), $request->user());

        if ($search = $request->query('search')) {
            $query->where(function ($query) use ($search) {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($organizationId = $request->query('organization_id')) {
            $query->where('organization_id', $organizationId);
        }

        if ($ownerId = $request->query('owner_id')) {
            $query->where('owner_id', $ownerId);
        }

        if ($teamId = $request->query('team_id')) {
            $query->where('team_id', $teamId);
        }

        $contacts = $query->orderBy('last_name')->paginate(15)->withQueryString();

        return view('crm.contacts.index', [
            'contacts' => $contacts,
            'filters' => $request->only(['search', 'organization_id', 'owner_id', 'team_id']),
            'organizations' => Organization::orderBy('name')->get(),
            'teams' => Team::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Contact::class);

        return view('crm.contacts.create', [
            'organizations' => Organization::orderBy('name')->get(),
            'teams' => Team::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(),
            'selectedOrganizationId' => $request->query('organization_id'),
        ]);
    }

    public function store(StoreContactRequest $request): RedirectResponse
    {
        $contact = $this->contacts->create($request->user(), $request->validated());

        return redirect()->route('crm.contacts.show', $contact)->with('status', 'Contact created.');
    }

    public function show(Contact $contact): View
    {
        $this->authorize('view', $contact);

        $contact->load(['organization', 'owner', 'team', 'leads.owner', 'opportunities.owner']);

        return view('crm.contacts.show', ['contact' => $contact]);
    }

    public function edit(Contact $contact): View
    {
        $this->authorize('update', $contact);

        return view('crm.contacts.edit', [
            'contact' => $contact,
            'organizations' => Organization::orderBy('name')->get(),
            'teams' => Team::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(),
        ]);
    }

    public function update(UpdateContactRequest $request, Contact $contact): RedirectResponse
    {
        $this->contacts->update($request->user(), $contact, $request->validated());

        return redirect()->route('crm.contacts.show', $contact)->with('status', 'Contact updated.');
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $this->authorize('delete', $contact);

        $this->contacts->archive($contact);

        return redirect()->route('crm.contacts.index')->with('status', 'Contact archived.');
    }
}
