<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Phase 11: the single entry point for CLAUDE.md's "Log security-relevant
 * events and sensitive workflow actions (role/team/ownership changes,
 * export, integration connection changes, AI-proposed actions accepted/
 * rejected)" requirement. Every call site funnels through here so every
 * audit entry has an identical, predictable shape — never a token,
 * password, or other secret in $context (callers are responsible for
 * that; this class does not attempt to guess and redact arbitrary keys).
 *
 * Writes to the dedicated `audit` log channel (config/logging.php) —
 * deliberately separate from general application logs so it can carry
 * its own retention policy. This is a logging aid, not the authorization
 * mechanism: it never decides whether an action is permitted, only
 * records that it happened.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(string $event, User $actor, array $context = []): void
    {
        Log::channel('audit')->info($event, array_merge([
            'actor_id' => $actor->id,
            'actor_role' => $actor->role->value,
        ], $context));
    }
}
