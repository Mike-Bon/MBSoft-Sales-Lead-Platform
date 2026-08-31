<?php

namespace Tests\Feature\MarketIntelligence;

use App\Contracts\MarketIntelligence\SearchProvider;
use App\Enums\ActivityType;
use App\Enums\AgentIdentifier;
use App\Enums\ProspectProposalStatus;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\ProspectLeadProposal;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\Tools\CheckProspectDuplicatesTool;
use App\Services\Ai\Tools\DiscoverProspectsTool;
use App\Services\Ai\Tools\PrepareProspectForCrmTool;
use App\Services\Ai\Tools\QualifyProspectsTool;
use App\Services\Ai\Tools\ScoreProspectsTool;
use App\Support\MarketIntelligence\OutboundUrlGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSearchProvider;
use Tests\Support\ProspectFixtures;
use Tests\TestCase;

/**
 * V2.6 (spec §28/§29/§30): deterministic end-to-end UAT of the whole V2
 * workflow — Internet → discover → qualify → score → duplicate check →
 * prepare proposal → human review → explicit confirm → V1 write — for a
 * Manager and a Team Head, plus a full negative walkthrough for a Team
 * Member. No live Brave, no live web.
 */
class V2WorkflowUatTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> */
    private const HOSTS = [
        'acme-cosmetics.test' => ['93.184.216.34'],
        'glow-beauty.test' => ['93.184.216.35'],
        'shine-skincare.test' => ['93.184.216.36'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->app->instance(OutboundUrlGuard::class, new OutboundUrlGuard(fn (string $host) => self::HOSTS[$host] ?? []));
        $this->app->instance(SearchProvider::class, FakeSearchProvider::withRows([
            ['title' => 'Acme Cosmetics', 'url' => 'https://acme-cosmetics.test/', 'description' => 'Lipstick and skincare in Cebu City.'],
            ['title' => 'Glow Beauty', 'url' => 'https://glow-beauty.test/', 'description' => 'Cosmetics store, Cebu City.'],
            ['title' => 'Shine Skincare', 'url' => 'https://shine-skincare.test/', 'description' => 'Skincare, Cebu City.'],
        ]));

        Http::fake([
            'acme-cosmetics.test/*' => Http::response(
                '<html><head><title>Acme Cosmetics — Cebu City</title>'
                .'<meta name="description" content="Acme Cosmetics sells cosmetics and skincare online in Cebu City. Nationwide delivery.">'
                .'</head><body>Acme Cosmetics, Cebu City. Add to cart and check out online. We deliver nationwide via LBC.'
                .'<a href="https://facebook.com/acmecosmetics">Facebook</a></body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
            'glow-beauty.test/*' => Http::response(
                '<html><head><title>Glow Beauty — Cebu City</title></head>'
                .'<body>Glow Beauty in Cebu City. Cosmetics and skincare. Shop now, add to cart.</body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
            'shine-skincare.test/*' => Http::response(
                '<html><head><title>Shine Skincare — Cebu City</title></head>'
                .'<body>Shine Skincare, Cebu City. Skincare products. Add to cart.</body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
        ]);
    }

    private function args(): array
    {
        return [
            'location' => 'Cebu City',
            'industry' => 'cosmetics',
            'product_keywords' => ['skincare'],
            'online_signals' => ['own_website'],
        ];
    }

    public function test_manager_end_to_end_discover_qualify_score_dedupe_prepare_confirm(): void
    {
        $manager = User::factory()->manager()->create();

        // A CRM organisation that is an exact duplicate of "Glow Beauty".
        $team = Team::factory()->create();
        Organization::factory()->forTeam($team)->create(['name' => 'Glow Beauty', 'website' => 'https://glow-beauty.test/']);

        // 1–3. discover → qualify → score
        $discover = app(DiscoverProspectsTool::class)->execute($manager, $this->args());
        $this->assertSame('ok', $discover['status']);
        $this->assertNotEmpty($discover['prospects']);

        $qualify = app(QualifyProspectsTool::class)->execute($manager, $this->args());
        $this->assertSame('ok', $qualify['status']);

        $score = app(ScoreProspectsTool::class)->execute($manager, $this->args());
        $this->assertSame('ok', $score['status']);
        // Ranked, highest first.
        $scores = array_column($score['scored_prospects'], 'total_score');
        $this->assertSame($scores, collect($scores)->sortDesc()->values()->all());

        // 7. duplicate check org-wide
        $identities = array_map(fn ($p) => $p['identity'] + [
            'total_score' => $p['total_score'], 'priority' => $p['priority'],
            'qualification_outcome' => $p['qualification_outcome'], 'scoring_model' => $p['scoring_model'],
        ], $score['scored_prospects']);
        $dupe = app(CheckProspectDuplicatesTool::class)->execute($manager, ['prospects' => $identities]);
        $this->assertSame('ok', $dupe['status']);

        $byDomain = collect($dupe['checked_prospects'])->keyBy('domain');
        // 8. Glow Beauty is an exact duplicate → blocked.
        $this->assertSame('exact_duplicate', $byDomain['glow-beauty.test']['duplicate_status']);
        // 10. Acme + Shine are new → no_match.
        $this->assertSame('no_match', $byDomain['acme-cosmetics.test']['duplicate_status']);

        // 11–12. prepare the no_match prospect — NO CRM write yet.
        $orgsBefore = Organization::count();
        $leadsBefore = Lead::count();
        $prepare = app(PrepareProspectForCrmTool::class)->execute($manager, [
            'duplicate_check' => $byDomain['acme-cosmetics.test'],
            'industry' => 'cosmetics', 'location' => 'Cebu City',
        ]);
        $this->assertSame('ok', $prepare['status']);
        $this->assertSame('eligible_for_confirmation', $prepare['eligibility']);
        $this->assertSame($orgsBefore, Organization::count());
        $this->assertSame($leadsBefore, Lead::count());

        // The blocked (Glow Beauty) prospect: preparing it is BLOCKED.
        $blockedPrepare = app(PrepareProspectForCrmTool::class)->execute($manager, ['duplicate_check' => $byDomain['glow-beauty.test']]);
        $this->assertSame('blocked_duplicate', $blockedPrepare['eligibility']);

        // 13–17. human opens the review page, edits, clicks Create Lead.
        $proposal = ProspectLeadProposal::findOrFail($prepare['proposal_id']);
        $this->actingAs($manager)->get($prepare['review_url'])->assertOk()->assertSee('Create Lead');

        $response = $this->actingAs($manager)->post(route('market-intelligence.prospect-proposals.confirm', $proposal), [
            'fingerprint' => $proposal->fingerprint,
            'business_name' => 'Acme Cosmetics Inc',
            'industry' => 'cosmetics',
            'website' => 'https://acme-cosmetics.test/',
            'city' => 'Cebu City',
            'country' => 'Philippines',
            'lead_description' => 'Reviewed — strong fit for parcel delivery.',
        ]);

        $lead = Lead::firstOrFail();
        $response->assertRedirect(route('crm.leads.show', $lead));

        // 17–18. exactly one Organization + Lead, correct source/activity.
        $this->assertSame($orgsBefore + 1, Organization::count());
        $this->assertSame($leadsBefore + 1, Lead::count());
        $this->assertSame('Acme Cosmetics Inc', $lead->organization->name);
        $this->assertSame('Market Intelligence', $lead->source);
        $this->assertSame('Market Intelligence', $lead->organization->source);
        $this->assertTrue(Activity::where('lead_id', $lead->id)->where('subject', 'Lead created')->exists());
        $this->assertSame($manager->id, $lead->owner_id);

        // 19. no outreach.
        $this->assertSame(0, Communication::count());
        $this->assertFalse(Activity::where('lead_id', $lead->id)->whereIn('type', [ActivityType::Email, ActivityType::WhatsApp])->exists());

        $this->assertSame(ProspectProposalStatus::Confirmed, $proposal->fresh()->status);
    }

    public function test_team_head_end_to_end_restricted_duplicate_stays_invisible(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $head = User::factory()->teamHead($teamA)->create();

        // Team B has an exact duplicate of Acme — invisible to Head A.
        Organization::factory()->forTeam($teamB)->create(['name' => 'Acme Cosmetics', 'website' => 'https://acme-cosmetics.test/']);

        $score = app(ScoreProspectsTool::class)->execute($head, $this->args());
        $acme = collect($score['scored_prospects'])->firstWhere('domain', 'acme-cosmetics.test');

        $dupe = app(CheckProspectDuplicatesTool::class)->execute($head, ['prospects' => [$acme['identity']]]);
        $checked = $dupe['checked_prospects'][0];
        $this->assertSame('no_match', $checked['duplicate_status']);
        $this->assertSame(0, $checked['candidates_examined']);

        $prepare = app(PrepareProspectForCrmTool::class)->execute($head, ['duplicate_check' => $checked, 'industry' => 'cosmetics']);
        $proposal = ProspectLeadProposal::findOrFail($prepare['proposal_id']);

        $this->actingAs($head)->post(route('market-intelligence.prospect-proposals.confirm', $proposal), [
            'fingerprint' => $proposal->fingerprint,
            'business_name' => 'Acme Cosmetics (Team A)',
            'website' => 'https://acme-cosmetics.test/',
        ])->assertRedirect();

        $lead = Lead::where('source', 'Market Intelligence')->firstOrFail();
        $this->assertSame($teamA->id, $lead->team_id, 'Forced to Head A\'s team.');
        $this->assertSame($teamA->id, $lead->organization->team_id);
        // Team B's Acme still stands untouched, and now there are two orgs.
        $this->assertSame(2, Organization::count());
    }

    public function test_team_member_is_denied_at_every_step_of_the_workflow(): void
    {
        $member = User::factory()->teamMember()->create();

        // Agent dropdown / routing.
        $this->assertFalse(AgentIdentifier::MarketIntelligence->isAvailableTo($member));
        $this->actingAs($member)->get('/assistant')->assertOk()->assertDontSee('Market Intelligence');

        // Every discover/qualify/score MI tool, invoked directly.
        $denied = 0;
        foreach ([DiscoverProspectsTool::class, QualifyProspectsTool::class, ScoreProspectsTool::class] as $tool) {
            try {
                app($tool)->execute($member, $this->args());
                $this->fail("{$tool} did not deny a Team Member.");
            } catch (AuthorizationException) {
                $denied++;
            }
        }
        $this->assertSame(3, $denied);
    }

    public function test_team_member_cannot_reach_the_dedupe_prepare_tools_or_the_proposal_routes(): void
    {
        $member = User::factory()->teamMember()->create();
        $managerProposal = ProspectLeadProposal::factory()->ownedBy(User::factory()->manager()->create())->withFingerprint()->create();

        foreach ([CheckProspectDuplicatesTool::class, PrepareProspectForCrmTool::class] as $tool) {
            try {
                app($tool)->execute($member, $tool === CheckProspectDuplicatesTool::class
                    ? ['prospects' => [['business' => 'X', 'domain' => 'x.example']]]
                    : ['duplicate_check' => ProspectFixtures::duplicateCheckResult()]);
                $this->fail("{$tool} did not deny a Team Member.");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $this->actingAs($member)->get(route('market-intelligence.prospect-proposals.show', $managerProposal))->assertForbidden();
        $this->actingAs($member)->post(route('market-intelligence.prospect-proposals.confirm', $managerProposal), [
            'fingerprint' => $managerProposal->fingerprint, 'business_name' => 'Hax',
        ])->assertForbidden();
        $this->actingAs($member)->post(route('market-intelligence.prospect-proposals.cancel', $managerProposal))->assertForbidden();

        $this->assertSame(0, Lead::where('source', 'Market Intelligence')->count());
        $this->assertSame(ProspectProposalStatus::Pending, $managerProposal->fresh()->status);
    }

    public function test_an_unauthenticated_visitor_cannot_reach_the_proposal_routes(): void
    {
        $proposal = ProspectLeadProposal::factory()->ownedBy(User::factory()->manager()->create())->withFingerprint()->create();

        $this->get(route('market-intelligence.prospect-proposals.show', $proposal))->assertRedirect(route('login'));
        $this->post(route('market-intelligence.prospect-proposals.confirm', $proposal), [])->assertRedirect(route('login'));
    }
}
