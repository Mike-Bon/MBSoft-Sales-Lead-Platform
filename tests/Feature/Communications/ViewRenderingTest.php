<?php

namespace Tests\Feature\Communications;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every new Phase 6 screen actually renders successfully — see
 * tests/Feature/Crm/ViewRenderingTest.php for why this exists as its
 * own file rather than being assumed from status-code-only assertions
 * elsewhere. A Blade compile error, undefined variable, or `@selected`-
 * inside-`<flux:select.option>` bug shows up here even when the
 * underlying service logic is fully covered.
 */
class ViewRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_communication_view_renders(): void
    {
        $manager = User::factory()->manager()->create();
        EmailAccount::factory()->create(['user_id' => $manager->id]);
        WhatsAppBusinessNumber::factory()->create();
        MessageTemplate::factory()->create();
        MessageTemplate::factory()->whatsapp()->create();
        $communication = Communication::factory()->create(['user_id' => $manager->id, 'team_id' => null]);

        $this->actingAs($manager)->get('/communications')->assertOk();
        $this->actingAs($manager)->get("/communications/{$communication->id}")->assertOk();
        $this->actingAs($manager)->get('/communications/compose/email')->assertOk();
        $this->actingAs($manager)->get('/communications/compose/whatsapp')->assertOk();
        $this->actingAs($manager)->get('/communications/templates')->assertOk();
        $this->actingAs($manager)->get('/communications/templates/create')->assertOk();
        $this->actingAs($manager)->get('/communications/whatsapp-numbers')->assertOk();
        $this->actingAs($manager)->get('/communications/whatsapp-numbers/create')->assertOk();
        $this->actingAs($manager)->get('/communications/email-account')->assertOk();
    }

    public function test_the_compose_email_view_renders_without_a_connected_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/communications/compose/email')->assertOk();
    }

    public function test_the_compose_whatsapp_view_renders_without_any_numbers_available(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/communications/compose/whatsapp')->assertOk();
    }

    public function test_the_template_edit_view_renders(): void
    {
        $manager = User::factory()->manager()->create();
        $template = MessageTemplate::factory()->create(['created_by' => $manager->id]);

        $this->actingAs($manager)->get("/communications/templates/{$template->id}/edit")->assertOk();
    }

    public function test_literal_sub_paths_are_not_shadowed_by_the_communication_show_route(): void
    {
        // Regression guard for the routing-order bug fixed while building
        // this phase: communications/{communication} must never capture
        // communications/templates, communications/whatsapp-numbers, or
        // communications/email-account.
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/communications/templates')->assertOk();
        $this->actingAs($manager)->get('/communications/whatsapp-numbers')->assertOk();
        $this->actingAs($manager)->get('/communications/email-account')->assertOk();
    }

    /**
     * STEP 15: a Lead/Opportunity's own timeline renders correctly when
     * one of its activities is linked to a Communication — the
     * distinguishing status badge path is exercised, not just the
     * plain-activity path already covered by tests/Feature/Crm/
     * ViewRenderingTest.php.
     */
    public function test_the_lead_and_opportunity_timelines_render_a_linked_communication_badge(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create();
        $opportunity = Opportunity::factory()->create();

        foreach ([$lead, $opportunity] as $record) {
            $column = $record instanceof Lead ? 'lead_id' : 'opportunity_id';
            $communication = Communication::factory()->sent()->create(['user_id' => $manager->id, $column => $record->id]);
            $activity = Activity::factory()->create(['user_id' => $manager->id, 'type' => ActivityType::Email, $column => $record->id]);
            $activity->communication_id = $communication->id;
            $activity->save();
        }

        $this->actingAs($manager)->get("/leads/{$lead->id}")->assertOk()->assertSee('Sent');
        $this->actingAs($manager)->get("/opportunities/{$opportunity->id}")->assertOk()->assertSee('Sent');
    }
}
