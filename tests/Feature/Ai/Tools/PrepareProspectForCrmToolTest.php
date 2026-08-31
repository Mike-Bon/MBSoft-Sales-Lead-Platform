<?php

namespace Tests\Feature\Ai\Tools;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\Tools\PrepareProspectForCrmTool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProspectFixtures;
use Tests\TestCase;

/**
 * V2.5 (spec §3/§38/§41): the prepare_prospect_for_crm tool is
 * proposal-only — it never writes a Lead or Organization and exposes no
 * confirm/owner/team parameter.
 */
class PrepareProspectForCrmToolTest extends TestCase
{
    use RefreshDatabase;

    private function tool(): PrepareProspectForCrmTool
    {
        return app(PrepareProspectForCrmTool::class);
    }

    public function test_a_manager_can_prepare_a_proposal_but_no_crm_record_is_written(): void
    {
        $result = $this->tool()->execute(User::factory()->manager()->create(), [
            'duplicate_check' => ProspectFixtures::duplicateCheckResult(),
            'industry' => 'cosmetics',
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('review_url', $result);
        $this->assertArrayHasKey('eligibility', $result);
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, Organization::count());
        $this->assertDatabaseCount('prospect_lead_proposals', 1);
    }

    public function test_a_team_head_can_prepare(): void
    {
        $result = $this->tool()->execute(User::factory()->teamHead()->create(), [
            'duplicate_check' => ProspectFixtures::duplicateCheckResult(),
        ]);
        $this->assertSame('ok', $result['status']);
    }

    public function test_a_team_member_is_denied(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->tool()->execute(User::factory()->teamMember()->create(), ['duplicate_check' => ProspectFixtures::duplicateCheckResult()]);
    }

    public function test_a_plain_user_is_denied(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->tool()->execute(User::factory()->create(), ['duplicate_check' => ProspectFixtures::duplicateCheckResult()]);
    }

    public function test_the_definition_has_no_confirm_owner_team_or_create_parameter(): void
    {
        $params = $this->tool()->definition()->parameters['properties'];

        $this->assertSame('prepare_prospect_for_crm', $this->tool()->definition()->name);
        foreach (['confirm', 'confirmed', 'create', 'owner_id', 'team_id', 'status', 'eligibility', 'acknowledge_possible_duplicate'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $params);
        }
    }

    public function test_a_blocked_prospect_returns_a_blocked_eligibility_and_no_confirmation_path(): void
    {
        $result = $this->tool()->execute(User::factory()->manager()->create(), [
            'duplicate_check' => ProspectFixtures::duplicateCheckResult([
                'duplicate_status' => 'exact_duplicate', 'duplicate_status_label' => 'EXACT DUPLICATE',
            ]),
        ]);

        $this->assertSame('blocked_duplicate', $result['eligibility']);
        $this->assertStringContainsString('No lead can be created', $result['next_step_for_human']);
    }

    public function test_the_notice_states_the_ai_cannot_confirm_or_create(): void
    {
        $result = $this->tool()->execute(User::factory()->manager()->create(), [
            'duplicate_check' => ProspectFixtures::duplicateCheckResult(),
        ]);

        $this->assertStringContainsString('PROPOSAL only', $result['notice']);
        $this->assertStringContainsString('cannot confirm', $result['notice']);
    }
}
