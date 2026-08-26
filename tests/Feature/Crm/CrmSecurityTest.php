<?php

namespace Tests\Feature\Crm;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 13 / STEP 21 items 26-28: direct URL access, direct request
 * manipulation, and user-supplied team ids must never bypass
 * authorization, across every CRM entity — not just the UI paths a real
 * user would click through.
 */
class CrmSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_url_access_cannot_bypass_authorization_for_any_crm_entity(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $organization = Organization::factory()->forTeam($otherTeam)->create();
        $contact = Contact::factory()->forTeam($otherTeam)->create();
        $lead = Lead::factory()->forTeam($otherTeam)->create();
        $opportunity = Opportunity::factory()->forTeam($otherTeam)->create();

        $this->actingAs($head)->get("/organizations/{$organization->id}")->assertForbidden();
        $this->actingAs($head)->get("/contacts/{$contact->id}")->assertForbidden();
        $this->actingAs($head)->get("/leads/{$lead->id}")->assertForbidden();
        $this->actingAs($head)->get("/opportunities/{$opportunity->id}")->assertForbidden();
    }

    public function test_direct_request_manipulation_cannot_bypass_team_restrictions(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $lead = Lead::factory()->forTeam($otherTeam)->create();

        // A raw PUT with a crafted payload, not a form submission clicked
        // through the UI.
        $response = $this->actingAs($head)->put("/leads/{$lead->id}", [
            'status' => 'converted',
            'priority' => 'high',
            'owner_id' => $head->id,
            'team_id' => $ownTeam->id,
        ]);

        $response->assertForbidden();
        $this->assertSame($otherTeam->id, $lead->fresh()->team_id);
    }

    public function test_user_supplied_team_id_cannot_bypass_authorization_on_create(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $member = User::factory()->teamMember($ownTeam)->create();

        $this->actingAs($member)->post('/organizations', [
            'name' => 'Smuggled Org',
            'team_id' => $otherTeam->id,
            'owner_id' => $member->id,
        ])->assertRedirect();

        $organization = Organization::where('name', 'Smuggled Org')->firstOrFail();

        // The supplied team_id is silently discarded server-side and
        // replaced with the actor's own team, never trusted as-is.
        $this->assertSame($ownTeam->id, $organization->team_id);
        $this->assertNotSame($otherTeam->id, $organization->team_id);
    }
}
