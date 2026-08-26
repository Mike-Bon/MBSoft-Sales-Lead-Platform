<?php

namespace Tests\Feature\Crm;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_belongs_to_the_correct_organization(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        $this->actingAs($manager)->post('/contacts', [
            'organization_id' => $organization->id,
            'first_name' => 'Jamie',
            'last_name' => 'Lee',
        ])->assertRedirect();

        $contact = Contact::where('first_name', 'Jamie')->firstOrFail();
        $this->assertSame($organization->id, $contact->organization_id);
    }

    public function test_unauthorized_user_cannot_access_another_teams_protected_contact(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $contact = Contact::factory()->forTeam($otherTeam)->create();

        $this->actingAs($head)->get("/contacts/{$contact->id}")->assertForbidden();
        $this->actingAs($head)->get("/contacts/{$contact->id}/edit")->assertForbidden();
    }
}
