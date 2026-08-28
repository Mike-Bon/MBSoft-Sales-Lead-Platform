<?php

namespace App\Http\Controllers\Organisation;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AuditLogger;
use App\Support\CompanyBranding;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Production-readiness branding correction: the Manager sets the
 * company name and logo used by the login page and the app sidebar.
 * Manager-only — this is organisation-level configuration, the same
 * authority bar as CostToServeController's settings page.
 *
 * Reuses App\Models\Setting (Phase 12A). The logo is stored inline in
 * the settings row as a size-capped base64 `data:` URI rather than on a
 * filesystem disk: the deploy target has no persistent local storage
 * and no object store is configured, and a nav/login logo is small — so
 * this is the smallest mechanism that actually serves on the deployed
 * app. See App\Support\CompanyBranding and docs/DEPLOYMENT.md.
 */
class CompanySettingsController extends Controller
{
    /** Max accepted upload size, in kilobytes. base64 of this still fits the settings TEXT column comfortably. */
    private const MAX_LOGO_KB = 256;

    public function edit(Request $request): View
    {
        abort_unless($request->user()->isManager(), 403);

        return view('organisation.company', [
            'companyName' => CompanyBranding::name(),
            'hasCustomLogo' => CompanyBranding::hasCustomLogo(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isManager(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'logo' => [
                'nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp',
                'max:'.self::MAX_LOGO_KB,
                'dimensions:max_width=2000,max_height=2000',
            ],
            'remove_logo' => ['nullable', 'boolean'],
        ], [
            'logo.max' => 'The logo must be :max KB or smaller.',
            'logo.image' => 'The logo must be a PNG, JPG or WebP image.',
        ]);

        Setting::setValue(CompanyBranding::NAME_KEY, trim($validated['name']));

        $logoChanged = false;

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $dataUri = 'data:'.$file->getMimeType().';base64,'
                .base64_encode((string) file_get_contents($file->getRealPath()));
            Setting::setValue(CompanyBranding::LOGO_KEY, $dataUri);
            $logoChanged = true;
        } elseif ($request->boolean('remove_logo')) {
            Setting::setValue(CompanyBranding::LOGO_KEY, '');
            $logoChanged = true;
        }

        AuditLogger::record('company.branding.updated', $user, [
            'name' => trim($validated['name']),
            'logo_changed' => $logoChanged,
            'has_custom_logo' => CompanyBranding::hasCustomLogo(),
        ]);

        return redirect()->route('organisation.company.edit')
            ->with('status', 'Company branding updated.');
    }
}
