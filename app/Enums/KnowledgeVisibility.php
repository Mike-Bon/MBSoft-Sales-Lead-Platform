<?php

namespace App\Enums;

/**
 * STEP 20/25: which users may retrieve a knowledge document — never
 * enforced by the search index itself, only by KnowledgeSearchService
 * filtering eligible documents BEFORE any search runs (see its own
 * docblock). Mirrors the org-wide/team-scoped shape already used by
 * MessageTemplate and WhatsAppBusinessNumber (`team_id` nullable), with
 * one addition — Manager, for genuinely management-only material (e.g.
 * compensation policy) that a Team Head must never retrieve.
 */
enum KnowledgeVisibility: string
{
    case Organisation = 'organisation';
    case Manager = 'manager';
    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            self::Organisation => 'Organisation-wide',
            self::Manager => 'Manager only',
            self::Team => 'Team only',
        };
    }
}
