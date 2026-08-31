<?php

namespace Tests\Feature\Ai\Tools;

use App\Contracts\MarketIntelligence\SearchProvider;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\Tools\CheckProspectDuplicatesTool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * V2.4 (spec §22/§35): the check_prospect_duplicates tool contract —
 * Manager/Team-Head only, identity-list input validated, structured
 * output, no pipeline replay.
 */
class CheckProspectDuplicatesToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prove the tool never touches the web: any HTTP call fails the test.
        Http::preventStrayRequests();
        $this->app->instance(SearchProvider::class, new class implements SearchProvider
        {
            public function search(string $query, int $limit): array
            {
                throw new \RuntimeException('duplicate check must not search the web');
            }

            public function name(): string
            {
                return 'forbidden';
            }
        });
    }

    private function tool(): CheckProspectDuplicatesTool
    {
        return app(CheckProspectDuplicatesTool::class);
    }

    private function args(): array
    {
        return ['prospects' => [
            ['business' => 'ABC Beauty Corporation', 'website' => 'https://abcbeauty.ph/', 'domain' => 'abcbeauty.ph', 'location' => 'Cebu City'],
        ]];
    }

    public function test_a_manager_can_check_duplicates(): void
    {
        $team = Team::factory()->create();
        Organization::factory()->forTeam($team)->create([
            'name' => 'ABC Beauty Corp.', 'website' => 'https://www.abcbeauty.ph/',
        ]);

        $result = $this->tool()->execute(User::factory()->manager()->create(), $this->args());

        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('checked_prospects', $result);
        $this->assertArrayHasKey('match_policy', $result);
        $this->assertArrayHasKey('notice', $result);
        $this->assertSame('exact_duplicate', $result['checked_prospects'][0]['duplicate_status']);
    }

    public function test_a_team_head_can_check_duplicates(): void
    {
        $result = $this->tool()->execute(User::factory()->teamHead()->create(), $this->args());
        $this->assertArrayHasKey('checked_prospects', $result);
    }

    public function test_a_team_member_is_denied(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->tool()->execute(User::factory()->teamMember()->create(), $this->args());
    }

    public function test_a_plain_user_is_denied(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->tool()->execute(User::factory()->create(), $this->args());
    }

    public function test_an_empty_prospect_list_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->tool()->execute(User::factory()->manager()->create(), ['prospects' => []]);
    }

    public function test_a_missing_prospect_list_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->tool()->execute(User::factory()->manager()->create(), []);
    }

    public function test_the_definition_exposes_no_crm_search_write_or_status_override_parameter(): void
    {
        $params = $this->tool()->definition()->parameters['properties'];

        $this->assertSame('check_prospect_duplicates', $this->tool()->definition()->name);
        foreach (['team_id', 'owner_id', 'duplicate_status', 'match_strength', 'status', 'crm_query', 'sql', 'limit'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $params);
        }
        $this->assertArrayHasKey('prospects', $params);
    }

    public function test_score_fields_are_carried_through_and_not_recomputed(): void
    {
        $result = $this->tool()->execute(User::factory()->manager()->create(), [
            'prospects' => [[
                'business' => 'Brand New Widgets', 'domain' => 'brandnewwidgets.example',
                'total_score' => 77, 'priority' => 'high', 'qualification_outcome' => 'strong_match',
            ]],
        ]);

        $carried = $result['checked_prospects'][0]['carried_from_scoring'];
        $this->assertSame(77, $carried['total_score']);
        $this->assertSame('high', $carried['priority']);
        $this->assertSame('no_match', $result['checked_prospects'][0]['duplicate_status']);
    }

    public function test_the_batch_is_capped_by_the_application(): void
    {
        config(['services.market_intelligence.duplicate_check.max_prospects_per_check' => 2]);

        $result = $this->tool()->execute(User::factory()->manager()->create(), [
            'prospects' => array_fill(0, 10, ['business' => 'Some Co', 'domain' => 'someco.example']),
        ]);

        $this->assertLessThanOrEqual(2, count($result['checked_prospects']));
    }
}
