<?php

namespace App\Policies;

use App\Models\Communication;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCrmRecords;

/**
 * View access mirrors ActivityPolicy exactly (Communication carries the
 * same team_id/user_id shape as Activity). No update/delete: a
 * Communication's core content is never edited or removed once sent —
 * see the model's own docblock.
 *
 * This policy only governs who may *view* a Communication and who may
 * *attempt* to compose one (create) — it does not decide which account
 * a send may use or which CRM record it may attach to. That server-side
 * authorization is CommunicationAuthorizer's job (STEP 19/20), invoked
 * from CommunicationService, and is stricter than this policy.
 */
class CommunicationPolicy
{
    use AuthorizesCrmRecords;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Communication $communication): bool
    {
        return $this->canView($user, $communication, 'user_id');
    }

    public function create(User $user): bool
    {
        return true;
    }
}
