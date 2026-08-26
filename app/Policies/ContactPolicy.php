<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCrmRecords;

class ContactPolicy
{
    use AuthorizesCrmRecords;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contact $contact): bool
    {
        return $this->canView($user, $contact);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->canManage($user, $contact);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $this->canDelete($user, $contact);
    }
}
