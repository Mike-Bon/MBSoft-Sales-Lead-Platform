<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsAppBusinessNumber;

/**
 * STEP 11: WhatsApp business numbers are organisation infrastructure —
 * connecting/disconnecting one is Manager-only, exactly like TeamPolicy.
 * Any authenticated user may still view the numbers available to them
 * (needed to populate the "send from" choice on the composer); actually
 * using one to send is a separate, narrower check
 * (CommunicationAuthorizer::authorizeWhatsAppSend), not this policy.
 */
class WhatsAppBusinessNumberPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WhatsAppBusinessNumber $number): bool
    {
        return $user->isManager() || $number->team_id === null || $number->team_id === $user->team_id;
    }

    public function create(User $user): bool
    {
        return $user->isManager();
    }

    public function update(User $user, WhatsAppBusinessNumber $number): bool
    {
        return $user->isManager();
    }

    public function delete(User $user, WhatsAppBusinessNumber $number): bool
    {
        return $user->isManager();
    }
}
