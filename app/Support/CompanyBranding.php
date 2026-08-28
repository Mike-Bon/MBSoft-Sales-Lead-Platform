<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * Production-readiness branding correction for V1: the company's own
 * name and logo, configurable at runtime, so the login page and the
 * app sidebar show the deploying company's identity rather than a
 * Laravel starter mark — and so a second company can adopt the same
 * codebase without touching a Blade template or source file.
 *
 * Reuses Phase 12A's generic App\Models\Setting key/value store (no new
 * settings architecture). Two keys:
 *   - company.name  — a plain string; falls back to config('app.name').
 *   - company.logo  — a full `data:image/...;base64,...` URI, or absent/
 *                     empty for "use the default mark". Stored inline in
 *                     the settings row rather than on a disk because the
 *                     deploy target (Vercel + Supabase) has no
 *                     persistent local filesystem and no object store is
 *                     configured; the upload is size-capped so the row
 *                     stays small. See CompanySettingsController.
 *
 * Fail-safe: if the settings table does not exist yet (its migration is
 * applied per-environment), every accessor quietly falls back to the
 * config/default rather than 500-ing every page, including login.
 *
 * Reads are a single indexed unique-key lookup each — cheap enough that
 * no caching layer is warranted (same call profile as Phase 12A's
 * CostToServeAccessService::isEnabled()).
 */
final class CompanyBranding
{
    public const NAME_KEY = 'company.name';

    public const LOGO_KEY = 'company.logo';

    public static function name(): string
    {
        $stored = trim((string) (self::setting(self::NAME_KEY) ?? ''));

        return $stored !== '' ? $stored : (string) config('app.name', 'Company');
    }

    /**
     * The configured logo as a data URI, or null when none is set (the
     * caller then renders the default mark).
     */
    public static function logo(): ?string
    {
        $stored = self::setting(self::LOGO_KEY);

        return is_string($stored) && str_starts_with($stored, 'data:image/') ? $stored : null;
    }

    public static function hasCustomLogo(): bool
    {
        return self::logo() !== null;
    }

    private static function setting(string $key): ?string
    {
        try {
            return Setting::getValue($key);
        } catch (Throwable) {
            return null;
        }
    }
}
