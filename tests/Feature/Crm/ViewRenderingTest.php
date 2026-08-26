<?php

namespace Tests\Feature\Crm;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every CRM screen actually renders successfully end-to-end for a
 * Manager. This exists because a Blade compilation bug (@selected()
 * breaking inside a <flux:select.option> component tag) previously went
 * undetected in several views whose success path no other test happened
 * to GET-render — status-code-only assertions on forbidden/redirect
 * cases are not enough on their own.
 */
class ViewRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_organization_view_renders(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        $this->actingAs($manager)->get('/organizations')->assertOk();
        $this->actingAs($manager)->get('/organizations/create')->assertOk();
        $this->actingAs($manager)->get("/organizations/{$organization->id}")->assertOk();
        $this->actingAs($manager)->get("/organizations/{$organization->id}/edit")->assertOk();
    }

    public function test_every_contact_view_renders(): void
    {
        $manager = User::factory()->manager()->create();
        $contact = Contact::factory()->create();

        $this->actingAs($manager)->get('/contacts')->assertOk();
        $this->actingAs($manager)->get('/contacts/create')->assertOk();
        $this->actingAs($manager)->get("/contacts/{$contact->id}")->assertOk();
        $this->actingAs($manager)->get("/contacts/{$contact->id}/edit")->assertOk();
    }

    public function test_every_lead_view_renders(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->ownedBy($manager)->create();

        $this->actingAs($manager)->get('/leads')->assertOk();
        $this->actingAs($manager)->get('/leads/create')->assertOk();
        $this->actingAs($manager)->get("/leads/{$lead->id}")->assertOk();
        $this->actingAs($manager)->get("/leads/{$lead->id}/edit")->assertOk();
    }

    public function test_every_opportunity_view_renders(): void
    {
        $manager = User::factory()->manager()->create();
        $opportunity = Opportunity::factory()->ownedBy($manager)->create();

        $this->actingAs($manager)->get('/opportunities')->assertOk();
        $this->actingAs($manager)->get('/opportunities/create')->assertOk();
        $this->actingAs($manager)->get("/opportunities/{$opportunity->id}")->assertOk();
        $this->actingAs($manager)->get("/opportunities/{$opportunity->id}/edit")->assertOk();
    }

    public function test_every_activity_view_renders(): void
    {
        $manager = User::factory()->manager()->create();
        Activity::factory()->by($manager)->create();

        $this->actingAs($manager)->get('/activities')->assertOk();
        $this->actingAs($manager)->get('/activities/create')->assertOk();
    }
}
