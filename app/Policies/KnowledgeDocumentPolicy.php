<?php

namespace App\Policies;

use App\Enums\KnowledgeVisibility;
use App\Models\KnowledgeDocument;
use App\Models\User;

/**
 * Phase 10 STEP 25/31: knowledge documents are organisation infrastructure
 * — authoring/versioning/archival is Manager-only, exactly like
 * WhatsAppBusinessNumberPolicy/TeamPolicy. `view` mirrors this policy's
 * visibility rule so the admin UI's listing/show pages show a Team Head
 * exactly what search_knowledge would (and would not) surface to them —
 * see KnowledgeSearchService's own authorization filter, which this
 * duplicates deliberately rather than delegating to, since a Policy and
 * a query-scoping method have different call shapes.
 */
class KnowledgeDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, KnowledgeDocument $document): bool
    {
        if ($user->isManager()) {
            return true;
        }

        return match ($document->visibility) {
            KnowledgeVisibility::Organisation => true,
            KnowledgeVisibility::Manager => false,
            KnowledgeVisibility::Team => $document->team_id === $user->team_id,
        };
    }

    public function create(User $user): bool
    {
        return $user->isManager();
    }

    public function update(User $user, KnowledgeDocument $document): bool
    {
        return $user->isManager();
    }

    public function delete(User $user, KnowledgeDocument $document): bool
    {
        return $user->isManager();
    }
}
