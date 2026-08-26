<?php

namespace App\Http\Controllers\Crm;

use App\Enums\ActivityType;
use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreActivityRequest;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Services\ActivityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * No edit/destroy: activities are immutable facts once recorded (see
 * App\Models\Activity).
 */
class ActivityController extends Controller
{
    use ScopesCrmQueries;

    public function __construct(private readonly ActivityService $activities) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Activity::class);

        $query = $this->scopeToUser(Activity::query()->with(['user', 'organization', 'contact', 'lead', 'opportunity']), $request->user(), 'user_id');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($leadId = $request->query('lead_id')) {
            $query->where('lead_id', $leadId);
        }

        if ($opportunityId = $request->query('opportunity_id')) {
            $query->where('opportunity_id', $opportunityId);
        }

        $activities = $query->orderByDesc('occurred_at')->paginate(20)->withQueryString();

        return view('crm.activities.index', [
            'activities' => $activities,
            'filters' => $request->only(['type', 'lead_id', 'opportunity_id']),
            'types' => ActivityType::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Activity::class);

        return view('crm.activities.create', [
            'types' => ActivityType::cases(),
            'organizations' => Organization::orderBy('name')->get(),
            'contacts' => Contact::orderBy('last_name')->get(),
            'leads' => Lead::orderByDesc('created_at')->get(),
            'opportunities' => Opportunity::orderByDesc('created_at')->get(),
            'context' => $request->only(['organization_id', 'contact_id', 'lead_id', 'opportunity_id']),
            'redirectTo' => $request->query('redirect_to'),
        ]);
    }

    public function store(StoreActivityRequest $request): RedirectResponse
    {
        $this->activities->create($request->user(), $request->validated());

        $redirectTo = $request->input('redirect_to');

        // Only ever redirect to a same-site, absolute path — never a
        // protocol-relative URL (`//evil.com`) or an external address.
        if ($redirectTo && str_starts_with($redirectTo, '/') && ! str_starts_with($redirectTo, '//')) {
            return redirect($redirectTo)->with('status', 'Activity logged.');
        }

        return redirect()->route('crm.activities.index')->with('status', 'Activity logged.');
    }
}
