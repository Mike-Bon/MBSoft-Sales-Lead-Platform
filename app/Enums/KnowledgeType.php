<?php

namespace App\Enums;

/**
 * STEP 5: the closed set of knowledge categories — deliberately small
 * ("do not create excessive taxonomy"). Also doubles as the STEP 24/25
 * agent tool permission matrix's vocabulary — see AppServiceProvider's
 * three SearchKnowledgeTool instances for exactly which of these each
 * agent may search.
 */
enum KnowledgeType: string
{
    case Policy = 'policy';
    case Sop = 'sop';
    case SalesPlaybook = 'sales_playbook';
    case ProductGuide = 'product_guide';
    case Training = 'training';
    case Faq = 'faq';
    case Reference = 'reference';

    public function label(): string
    {
        return match ($this) {
            self::Policy => 'Policy',
            self::Sop => 'SOP',
            self::SalesPlaybook => 'Sales Playbook',
            self::ProductGuide => 'Product Guide',
            self::Training => 'Training',
            self::Faq => 'FAQ',
            self::Reference => 'Reference',
        };
    }
}
