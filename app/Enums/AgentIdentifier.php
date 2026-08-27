<?php

namespace App\Enums;

use App\Models\User;

/**
 * STEP 3/6 (Phase 9): the specialized agents — a closed set (STEP 3
 * forbids adding more without the application requiring it). Used
 * everywhere an agent must be referenced, instead of a bare string, so
 * a typo'd identifier is a compile-time/static-analysis error, not a
 * silent routing bug.
 *
 * Phase 12 adds CostToServe as a fourth, deliberate, explicitly-scoped
 * expansion of this set (not a swarm/orchestrator — it is a fourth
 * standalone instance of the same generic Agent engine, exactly like
 * the original three). Manager/Team-Head only — commercial economics is
 * management-level information, matching CLAUDE.md's least-privilege
 * rule. isAvailableTo() is the single source of truth for this,
 * consulted by the assistant's dropdown, request validation, and
 * routing alike — never duplicated ad hoc.
 */
enum AgentIdentifier: string
{
    case Sales = 'sales';
    case Performance = 'performance';
    case Communication = 'communication';
    case CostToServe = 'cost_to_serve';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales Intelligence',
            self::Performance => 'Performance & Management',
            self::Communication => 'Communication & Follow-Up',
            self::CostToServe => 'Cost-to-Serve Intelligence',
        };
    }

    /**
     * Eligibility only — never a substitute for a tool's own
     * authorization, which re-derives its scope from the actor on
     * every call regardless of this check.
     */
    public function isAvailableTo(User $user): bool
    {
        return match ($this) {
            self::CostToServe => $user->isManager() || $user->isTeamHead(),
            default => true,
        };
    }
}
