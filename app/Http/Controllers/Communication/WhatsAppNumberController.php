<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreWhatsAppNumberRequest;
use App\Models\Team;
use App\Models\WhatsAppBusinessNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * STEP 11: Manager-only management of business-owned WhatsApp numbers
 * (see WhatsAppBusinessNumberPolicy). Registering a number here does not
 * itself talk to Meta — it records the identifiers of a number already
 * configured in Meta Business Manager (see docs/COMMUNICATIONS.md).
 */
class WhatsAppNumberController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', WhatsAppBusinessNumber::class);

        return view('communications.whatsapp-numbers.index', [
            'numbers' => WhatsAppBusinessNumber::with(['team', 'createdBy'])->orderBy('display_name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', WhatsAppBusinessNumber::class);

        return view('communications.whatsapp-numbers.create', [
            'teams' => Team::orderBy('name')->get(),
        ]);
    }

    public function store(StoreWhatsAppNumberRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $number = new WhatsAppBusinessNumber([
            'display_name' => $data['display_name'],
            'phone_number' => $data['phone_number'],
            'phone_number_id' => $data['phone_number_id'],
            'waba_id' => $data['waba_id'] ?? null,
        ]);
        $number->team_id = $data['team_id'] ?? null;
        $number->created_by = $request->user()->id;
        $number->save();

        return redirect()->route('communications.whatsapp-numbers.index')->with('status', 'WhatsApp number registered.');
    }

    public function destroy(WhatsAppBusinessNumber $whatsappNumber): RedirectResponse
    {
        $this->authorize('delete', $whatsappNumber);

        $whatsappNumber->delete();

        return redirect()->route('communications.whatsapp-numbers.index')->with('status', 'WhatsApp number removed.');
    }
}
