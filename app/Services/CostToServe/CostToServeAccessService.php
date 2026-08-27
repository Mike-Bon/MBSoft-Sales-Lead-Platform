<?php

namespace App\Services\CostToServe;

use App\Models\Setting;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Phase 12A: the single source of truth for Cost-to-Serve access,
 * deliberately separating the two concepts the spec requires stay
 * separate:
 *
 *   - isEnabled()        — GLOBAL FEATURE STATUS (is it on at all?)
 *   - isRoleAuthorized()  — USER/ROLE AUTHORIZATION (can this user ever
 *                           use it, independent of the global switch?)
 *   - canAccess()         — both of the above combined. This is the one
 *                           method every enforcement point in the
 *                           application actually calls.
 *
 * Turning the feature ON never grants a Team Head access — canAccess()
 * for a Team Head is `false` unconditionally, regardless of
 * isEnabled(). There is no per-Team-Head toggle (deliberately, per the
 * spec) — only Manager is ever role-authorized in this phase, leaving
 * room for a future, more granular rule without changing this method's
 * signature or any of its callers.
 *
 * No caching: a single indexed key lookup is cheap enough that adding
 * a cache layer here would only introduce a staleness/invalidation
 * risk for no measured benefit (CLAUDE.md: "do not prematurely
 * introduce infrastructure... measure first").
 */
class CostToServeAccessService
{
    private const SETTING_KEY = 'cost_to_serve.enabled';

    /**
     * STEP 3: enabled by default — a fresh installation (no row yet)
     * never requires the Manager to manually turn this on.
     */
    public function isEnabled(): bool
    {
        return Setting::getValue(self::SETTING_KEY, 'true') === 'true';
    }

    /**
     * Role authorization only — never considers the global switch.
     * Only a Manager is ever authorized in this phase; a Team Head is
     * unconditionally false here, both when the feature is on and off
     * (STEP 5).
     */
    public function isRoleAuthorized(User $user): bool
    {
        return $user->isManager();
    }

    /**
     * The combined check every enforcement point (routes, controllers,
     * AI tool access, the assistant dropdown/routing) actually calls.
     */
    public function canAccess(User $user): bool
    {
        return $this->isRoleAuthorized($user) && $this->isEnabled();
    }

    /**
     * @throws AuthorizationException
     */
    public function enable(User $actor): void
    {
        $this->setEnabled($actor, true);
    }

    /**
     * @throws AuthorizationException
     */
    public function disable(User $actor): void
    {
        $this->setEnabled($actor, false);
    }

    /**
     * STEP 4: only a Manager may ever change the global switch — this
     * is administration, checked independently of (and unaffected by)
     * the switch's own current value, so a Manager can always turn it
     * back on after turning it off.
     *
     * @throws AuthorizationException
     */
    private function setEnabled(User $actor, bool $value): void
    {
        if (! $actor->isManager()) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $previousState = $this->isEnabled();
        Setting::setValue(self::SETTING_KEY, $value ? 'true' : 'false');

        AuditLogger::record($value ? 'cost_to_serve.enabled' : 'cost_to_serve.disabled', $actor, [
            'previous_state' => $previousState,
            'new_state' => $value,
        ]);
    }
}
