<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\User;

/**
 * The single place an Activity row is ever written — used both for
 * manually logged activities (ActivityController) and for
 * system-generated timeline entries (lead created, status/stage changed,
 * reassigned) written by LeadService/OpportunityService. This is also
 * the future audit-log hook point referenced throughout those services:
 * every organisationally meaningful CRM event already flows through one
 * method here.
 */
class ActivityLogger
{
    /**
     * @param  array{organization_id?: ?int, contact_id?: ?int, lead_id?: ?int, opportunity_id?: ?int, subject?: ?string, description?: ?string, occurred_at?: \DateTimeInterface|string|null, communication_id?: ?int}  $attributes
     */
    public function log(User $actor, ActivityType $type, array $attributes = []): Activity
    {
        // Not Activity::create(): user_id/team_id are deliberately
        // excluded from Activity::$fillable (never trust request input
        // for who logged something), so mass assignment would silently
        // drop them here too. Set explicitly instead.
        $activity = new Activity([
            'organization_id' => $attributes['organization_id'] ?? null,
            'contact_id' => $attributes['contact_id'] ?? null,
            'lead_id' => $attributes['lead_id'] ?? null,
            'opportunity_id' => $attributes['opportunity_id'] ?? null,
            'type' => $type,
            'subject' => $attributes['subject'] ?? null,
            'description' => $attributes['description'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
        ]);

        $activity->user_id = $actor->id;
        $activity->team_id = $actor->team_id;

        // Set (Phase 6) when this activity represents a Communication —
        // see Activity::communication() / communications.md.
        $activity->communication_id = $attributes['communication_id'] ?? null;

        $activity->save();

        return $activity;
    }
}
