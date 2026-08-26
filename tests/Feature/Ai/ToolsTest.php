<?php

namespace Tests\Feature\Ai;

use App\Enums\OpportunityStage;
use App\Models\Communication;
use App\Models\Contact;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Opportunity;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use App\Services\Ai\Tools\DraftEmailTool;
use App\Services\Ai\Tools\DraftWhatsAppTool;
use App\Services\Ai\Tools\GetCommunicationHistoryTool;
use App\Services\Ai\Tools\GetContactTool;
use App\Services\Ai\Tools\GetFollowupsTool;
use App\Services\Ai\Tools\GetLeadTool;
use App\Services\Ai\Tools\GetMyPerformanceTool;
use App\Services\Ai\Tools\GetOpportunityTool;
use App\Services\Ai\Tools\GetPipelineSummaryTool;
use App\Services\Ai\Tools\GetTeamPerformanceTool;
use App\Services\Ai\Tools\SearchContactsTool;
use App\Services\Ai\Tools\SearchLeadsTool;
use App\Services\Ai\Tools\SearchOpportunitiesTool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * STEP 39/40/41/44: every tool is a thin, authorized window onto an
 * existing service/policy — these tests exist to prove that boundary
 * holds, independent of any LLM behaviour (the tools are called
 * directly, exactly as Agent would call them).
 */
class ToolsTest extends TestCase
{
    use RefreshDatabase;

    // ── Sales / CRM read tools ──────────────────────────────────────

    public function test_search_leads_never_returns_another_teams_leads(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $member = User::factory()->teamMember($teamA)->create();
        Lead::factory()->create(['team_id' => $teamA->id, 'owner_id' => $member->id]);
        Lead::factory()->create(['team_id' => $teamB->id, 'owner_id' => User::factory()->teamMember($teamB)->create()->id]);

        $result = app(SearchLeadsTool::class)->execute($member, []);

        $this->assertSame(1, $result['count']);
    }

    public function test_search_leads_ignores_a_team_id_filter_the_user_is_not_authorized_for(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $member = User::factory()->teamMember($teamA)->create();
        Lead::factory()->create(['team_id' => $teamB->id, 'owner_id' => User::factory()->teamMember($teamB)->create()->id]);

        // Even though the model explicitly asked for teamB's leads, the
        // underlying scopeToUser() already excluded them before this
        // filter is even applied — it can only narrow, never widen.
        $result = app(SearchLeadsTool::class)->execute($member, ['team_id' => $teamB->id]);

        $this->assertSame(0, $result['count']);
    }

    public function test_get_lead_denies_an_unauthorized_lead(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $member = User::factory()->teamMember($teamA)->create();
        $lead = Lead::factory()->create(['team_id' => $teamB->id, 'owner_id' => User::factory()->teamMember($teamB)->create()->id]);

        $this->expectException(AuthorizationException::class);

        app(GetLeadTool::class)->execute($member, ['lead_id' => $lead->id]);
    }

    public function test_get_lead_returns_curated_fields_for_an_authorized_lead(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create(['description' => 'Interested in bulk pricing']);

        $result = app(GetLeadTool::class)->execute($manager, ['lead_id' => $lead->id]);

        $this->assertSame($lead->id, $result['id']);
        $this->assertSame('Interested in bulk pricing', $result['description']);
        $this->assertArrayNotHasKey('created_at', $result);
        $this->assertArrayNotHasKey('deleted_at', $result);
    }

    public function test_search_opportunities_respects_a_value_range_on_top_of_authorization(): void
    {
        $manager = User::factory()->manager()->create();
        Opportunity::factory()->create(['value' => 1000]);
        Opportunity::factory()->create(['value' => 90000]);

        $result = app(SearchOpportunitiesTool::class)->execute($manager, ['value_min' => 50000]);

        $this->assertSame(1, $result['count']);
        $this->assertSame(90000.0, $result['opportunities'][0]['value']);
    }

    public function test_get_opportunity_denies_an_unauthorized_opportunity(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $member = User::factory()->teamMember($teamA)->create();
        $opportunity = Opportunity::factory()->create(['team_id' => $teamB->id, 'owner_id' => User::factory()->teamMember($teamB)->create()->id]);

        $this->expectException(AuthorizationException::class);

        app(GetOpportunityTool::class)->execute($member, ['opportunity_id' => $opportunity->id]);
    }

    public function test_search_contacts_data_minimizes_email_and_phone_in_list_results(): void
    {
        $manager = User::factory()->manager()->create();
        Contact::factory()->create(['email' => 'jamie@example.test', 'first_name' => 'Jamie']);

        $result = app(SearchContactsTool::class)->execute($manager, ['search' => 'Jamie']);

        $this->assertArrayNotHasKey('email', $result['contacts'][0]);
        $this->assertTrue($result['contacts'][0]['has_email']);
    }

    public function test_get_contact_denies_an_unauthorized_contact(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $member = User::factory()->teamMember($teamA)->create();
        $contact = Contact::factory()->create(['team_id' => $teamB->id, 'owner_id' => User::factory()->teamMember($teamB)->create()->id]);

        $this->expectException(AuthorizationException::class);

        app(GetContactTool::class)->execute($member, ['contact_id' => $contact->id]);
    }

    // ── Performance tools ───────────────────────────────────────────

    public function test_get_my_performance_returns_the_authoritative_snapshot_fields(): void
    {
        $user = User::factory()->create();
        Target::factory()->individual($user)->create(['target_amount' => 10000]);
        Opportunity::factory()->create([
            'owner_id' => $user->id,
            'stage' => OpportunityStage::ClosedWon,
            'value' => 4000,
            'closed_at' => now(),
        ]);

        $result = app(GetMyPerformanceTool::class)->execute($user, []);

        $this->assertTrue($result['has_target']);
        $this->assertSame(10000.0, $result['target']);
        $this->assertSame(4000.0, $result['actual']);
        $this->assertSame(40.0, $result['achievement_percent']);
    }

    public function test_get_team_performance_denies_a_team_head_requesting_another_team(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $teamHead = User::factory()->teamHead($ownTeam)->create();

        $this->expectException(AuthorizationException::class);

        app(GetTeamPerformanceTool::class)->execute($teamHead, ['team_id' => $otherTeam->id]);
    }

    public function test_get_team_performance_defaults_to_the_actors_own_team(): void
    {
        $team = Team::factory()->create();
        $teamHead = User::factory()->teamHead($team)->create();

        $result = app(GetTeamPerformanceTool::class)->execute($teamHead, []);

        $this->assertSame($team->name, $result['team']);
    }

    public function test_get_pipeline_summary_organisation_scope_is_manager_only(): void
    {
        $member = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(GetPipelineSummaryTool::class)->execute($member, ['scope' => 'organisation']);
    }

    public function test_get_pipeline_summary_mine_scope_only_includes_own_opportunities(): void
    {
        $user = User::factory()->create();
        Opportunity::factory()->create(['owner_id' => $user->id, 'value' => 5000, 'stage' => OpportunityStage::Proposal]);
        Opportunity::factory()->create(['owner_id' => User::factory()->create()->id, 'value' => 5000, 'stage' => OpportunityStage::Proposal]);

        $result = app(GetPipelineSummaryTool::class)->execute($user, ['scope' => 'mine']);

        $this->assertSame(5000.0, $result['total_open_pipeline']);
    }

    // ── Follow-up tools ──────────────────────────────────────────────

    public function test_get_followups_overdue_bucket_matches_the_dashboards_own_definition(): void
    {
        $user = User::factory()->create();
        Lead::factory()->create(['owner_id' => $user->id, 'next_follow_up_at' => now()->subDays(2)]);
        Lead::factory()->create(['owner_id' => $user->id, 'next_follow_up_at' => now()->addDays(2)]);

        $result = app(GetFollowupsTool::class)->execute($user, ['bucket' => 'overdue']);

        $this->assertSame(1, $result['count']);
    }

    public function test_get_followups_team_scope_requires_authorization(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $teamHead = User::factory()->teamHead($ownTeam)->create();

        $this->expectException(AuthorizationException::class);

        app(GetFollowupsTool::class)->execute($teamHead, ['bucket' => 'overdue', 'scope' => 'team', 'team_id' => $otherTeam->id]);
    }

    // ── Communication read tool ─────────────────────────────────────

    public function test_get_communication_history_requires_at_least_one_crm_id(): void
    {
        $manager = User::factory()->manager()->create();

        $this->expectException(ValidationException::class);

        app(GetCommunicationHistoryTool::class)->execute($manager, []);
    }

    public function test_get_communication_history_denies_an_unauthorized_contact(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $member = User::factory()->teamMember($teamA)->create();
        $contact = Contact::factory()->create(['team_id' => $teamB->id, 'owner_id' => User::factory()->teamMember($teamB)->create()->id]);

        $this->expectException(AuthorizationException::class);

        app(GetCommunicationHistoryTool::class)->execute($member, ['contact_id' => $contact->id]);
    }

    public function test_get_communication_history_summarizes_rather_than_dumps_the_full_body(): void
    {
        $manager = User::factory()->manager()->create();
        $contact = Contact::factory()->create();
        Communication::factory()->create(['contact_id' => $contact->id, 'body' => str_repeat('word ', 100)]);

        $result = app(GetCommunicationHistoryTool::class)->execute($manager, ['contact_id' => $contact->id]);

        $this->assertLessThan(strlen(str_repeat('word ', 100)), strlen($result['communications'][0]['summary']));
    }

    // ── Draft tools (never send) ────────────────────────────────────

    public function test_draft_email_without_a_connected_account_returns_a_non_draft_result(): void
    {
        $user = User::factory()->create();

        $result = app(DraftEmailTool::class)->execute($user, ['recipient' => 'x@example.test', 'subject' => 'Hi', 'body' => 'Hello']);

        $this->assertFalse($result['draft']);
        $this->assertSame('no_connected_email_account', $result['reason']);
    }

    public function test_draft_email_denies_drafting_against_an_unauthorized_crm_record(): void
    {
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $teamB = Team::factory()->create();
        $contact = Contact::factory()->create(['team_id' => $teamB->id, 'owner_id' => User::factory()->teamMember($teamB)->create()->id]);

        $this->expectException(AuthorizationException::class);

        app(DraftEmailTool::class)->execute($user, ['recipient' => 'x@example.test', 'subject' => 'Hi', 'body' => 'Hello', 'contact_id' => $contact->id]);
    }

    public function test_draft_email_never_creates_a_communication_row(): void
    {
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);

        app(DraftEmailTool::class)->execute($user, ['recipient' => 'x@example.test', 'subject' => 'Hi', 'body' => 'Hello']);

        $this->assertDatabaseCount('communications', 0);
    }

    public function test_draft_email_renders_a_template_using_the_same_renderer_as_a_real_send(): void
    {
        $user = User::factory()->create(['name' => 'Alex Rep']);
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $contact = Contact::factory()->create(['first_name' => 'Jamie', 'owner_id' => $user->id]);
        $template = MessageTemplate::factory()->create(['body' => 'Hi {{first_name}}, this is {{salesperson_name}}.']);

        $result = app(DraftEmailTool::class)->execute($user, ['recipient' => 'jamie@example.test', 'template_id' => $template->id, 'contact_id' => $contact->id]);

        $this->assertTrue($result['draft']);
        $this->assertSame('Hi Jamie, this is Alex Rep.', $result['body']);
    }

    public function test_draft_whatsapp_never_creates_a_communication_row(): void
    {
        $user = User::factory()->create();
        WhatsAppBusinessNumber::factory()->create(['team_id' => null]);

        app(DraftWhatsAppTool::class)->execute($user, ['recipient' => '+15550001111', 'body' => 'Hi']);

        $this->assertDatabaseCount('communications', 0);
    }

    public function test_draft_whatsapp_asks_which_number_when_more_than_one_is_available(): void
    {
        $manager = User::factory()->manager()->create();
        WhatsAppBusinessNumber::factory()->create(['team_id' => null, 'display_name' => 'Sales']);
        WhatsAppBusinessNumber::factory()->create(['team_id' => null, 'display_name' => 'Support']);

        $result = app(DraftWhatsAppTool::class)->execute($manager, ['recipient' => '+15550001111', 'body' => 'Hi']);

        $this->assertFalse($result['draft']);
        $this->assertSame('multiple_whatsapp_numbers_available', $result['reason']);
        $this->assertCount(2, $result['available_numbers']);
    }

    public function test_draft_whatsapp_denies_a_number_outside_the_actors_team(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $member = User::factory()->teamMember($teamA)->create();
        $number = WhatsAppBusinessNumber::factory()->create(['team_id' => $teamB->id]);

        $this->expectException(AuthorizationException::class);

        app(DraftWhatsAppTool::class)->execute($member, ['recipient' => '+15550001111', 'body' => 'Hi', 'whatsapp_number_id' => $number->id]);
    }
}
