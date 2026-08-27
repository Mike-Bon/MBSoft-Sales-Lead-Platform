<?php

namespace App\Enums;

/**
 * STEP 39: a meaningful, honest outcome state for a knowledge search —
 * never a fabricated numeric confidence score (CLAUDE.md/AgentPromptRules:
 * "never invent a fact"; a made-up 0.87 relevance number would be
 * exactly that).
 */
enum KnowledgeSearchStatus: string
{
    case Found = 'found';
    case Partial = 'partial';
    case Conflicting = 'conflicting';
    case NotFound = 'not_found';
}
