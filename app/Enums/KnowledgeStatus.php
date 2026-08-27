<?php

namespace App\Enums;

/**
 * STEP 8/9: the lifecycle of one knowledge document *version*. Only
 * Active versions are ever searched (KnowledgeSearchService) — Draft/
 * Processing/Failed are invisible to every agent, and Archived versions
 * are kept for history but excluded from retrieval the same way.
 */
enum KnowledgeStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case Active = 'active';
    case Archived = 'archived';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Processing => 'Processing',
            self::Active => 'Active',
            self::Archived => 'Archived',
            self::Failed => 'Failed',
        };
    }
}
