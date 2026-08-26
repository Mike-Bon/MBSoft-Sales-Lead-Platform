<?php

namespace App\Enums;

/**
 * STEP 5: the three initial workflows only. Deliberately a closed set,
 * not a user-extensible registry — STEP 30 forbids an arbitrary
 * workflow builder in V1.
 */
enum WorkflowType: string
{
    case DailyFollowUpReview = 'daily_follow_up_review';
    case OpportunityAttentionReview = 'opportunity_attention_review';
    case PerformanceExceptionReview = 'performance_exception_review';

    public function label(): string
    {
        return match ($this) {
            self::DailyFollowUpReview => 'Daily Follow-Up Review',
            self::OpportunityAttentionReview => 'Opportunity Attention Review',
            self::PerformanceExceptionReview => 'Performance Exception Review',
        };
    }
}
