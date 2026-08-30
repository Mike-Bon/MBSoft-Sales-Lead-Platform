<?php

namespace Tests\Feature\MarketIntelligence;

use App\Contracts\MarketIntelligence\SearchProvider;
use App\Services\MarketIntelligence\ProspectScoringService;
use App\Support\MarketIntelligence\CriterionEvaluation;
use App\Support\MarketIntelligence\CriterionKind;
use App\Support\MarketIntelligence\CriterionResult;
use App\Support\MarketIntelligence\EvidenceItem;
use App\Support\MarketIntelligence\EvidenceStrength;
use App\Support\MarketIntelligence\QualificationCriterion;
use App\Support\MarketIntelligence\QualificationOutcome;
use App\Support\MarketIntelligence\ScoredProspect;
use App\Support\MarketIntelligence\ScorePriority;
use App\Support\MarketIntelligence\ScoringModel;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProspectFixtures;
use Tests\TestCase;

/**
 * V2.3 (spec §19/§23/§33): the pure scoring core — deterministic, no
 * network, no LLM, no CRM. Drives scoreProspect() / rank() against
 * hand-built QualifiedProspects.
 */
class ProspectScoringTest extends TestCase
{
    private function service(): ProspectScoringService
    {
        return app(ProspectScoringService::class);
    }

    private function model(): ScoringModel
    {
        return ScoringModel::default();
    }

    private function strongCandidate(array $overrides = []): array
    {
        return array_merge([
            'location' => 'Cebu City',
            'category' => 'cosmetics',
            'observedProducts' => ['lipstick', 'skincare'],
            'onlineSellingEvidence' => true,
            'shippingEvidence' => true,
            'socialPresence' => ['https://facebook.com/abcbeauty'],
            'website' => 'https://abcbeauty.test/',
            'domain' => 'abcbeauty.test',
            'confidence' => 'high',
            'evidence' => [
                ProspectFixtures::evidence(EvidenceItem::TYPE_DESCRIPTION, 'Website: "ABC Beauty"', EvidenceStrength::Direct),
                ProspectFixtures::evidence(EvidenceItem::TYPE_ONLINE_SELLING, 'Source contains an online-selling indicator ("checkout").', EvidenceStrength::Direct),
                ProspectFixtures::evidence(EvidenceItem::TYPE_SHIPPING, 'Source mentions shipping.', EvidenceStrength::Direct),
                ProspectFixtures::evidence(EvidenceItem::TYPE_SOCIAL_PRESENCE, 'A public social/business profile link was found.', EvidenceStrength::Corroborating, 'facebook.com'),
            ],
        ], $overrides);
    }

    /**
     * @param  list<CriterionEvaluation>  $evaluations
     */
    private function score(array $evaluations, QualificationOutcome $outcome, array $candidateOverrides): ScoredProspect
    {
        return $this->service()->scoreProspect(
            ProspectFixtures::qualified($evaluations, $outcome, $candidateOverrides),
            $this->model(),
        );
    }

    public function test_a_fully_evidenced_strong_prospect_scores_high(): void
    {
        $c = $this->strongCandidate();
        $scored = $this->score([
            ProspectFixtures::evaluation(
                ProspectFixtures::criterion(QualificationCriterion::KEY_LOCATION, CriterionKind::Hard, 'Cebu City'),
                CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_LOCATION, 'Source names the area "cebu".', EvidenceStrength::Direct)],
            ),
            ProspectFixtures::evaluation(
                ProspectFixtures::criterion(QualificationCriterion::KEY_INDUSTRY, CriterionKind::Hard, 'cosmetics'),
                CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_PRODUCT, 'Source text mentions "cosmetics".', EvidenceStrength::Direct)],
            ),
            ProspectFixtures::evaluation(
                ProspectFixtures::criterion(QualificationCriterion::KEY_PRODUCT, CriterionKind::Supporting, 'lipstick'),
                CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_PRODUCT, 'Source text mentions "lipstick".', EvidenceStrength::Direct)],
            ),
            ProspectFixtures::evaluation(
                ProspectFixtures::criterion(QualificationCriterion::KEY_ONLINE_SELLING, CriterionKind::Supporting),
                CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_ONLINE_SELLING, 'checkout', EvidenceStrength::Direct)],
            ),
            ProspectFixtures::evaluation(
                ProspectFixtures::criterion(QualificationCriterion::KEY_SHIPPING, CriterionKind::Supporting),
                CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_SHIPPING, 'nationwide', EvidenceStrength::Direct)],
            ),
        ], QualificationOutcome::StrongMatch, $c);

        $this->assertGreaterThanOrEqual(90, $scored->totalScore);
        $this->assertLessThanOrEqual(100, $scored->totalScore);
        $this->assertSame(ScorePriority::High, $scored->priority);
        $this->assertNull($scored->cappedBy);

        $this->assertSame(20, $scored->dimension('industry_fit')->pointsAwarded);
        $this->assertSame(15, $scored->dimension('geography_fit')->pointsAwarded);
        $this->assertSame(20, $scored->dimension('online_selling')->pointsAwarded);
        $this->assertSame(15, $scored->dimension('physical_product_relevance')->pointsAwarded);
        $this->assertSame(15, $scored->dimension('shipping_signals')->pointsAwarded);
        $this->assertLessThanOrEqual(5, $scored->dimension('evidence_quality')->pointsAwarded);

        // Every awarded dimension has a reason and its evidence.
        foreach ($scored->dimensions as $d) {
            $this->assertNotSame('', $d->reason);
            if ($d->pointsAwarded > 0 && $d->key !== 'evidence_quality' && $d->key !== 'digital_activity') {
                $this->assertNotEmpty($d->evidence, "{$d->key} awarded points but shows no evidence");
            }
        }
    }

    public function test_unknown_criteria_earn_no_points_and_are_never_negative(): void
    {
        $scored = $this->score([
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_LOCATION, CriterionKind::Hard, 'Cebu City'), CriterionResult::Unknown),
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_INDUSTRY, CriterionKind::Hard, 'cosmetics'), CriterionResult::Unknown),
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_SHIPPING, CriterionKind::Supporting), CriterionResult::Unknown),
        ], QualificationOutcome::InsufficientEvidence, ['website' => null, 'domain' => null, 'category' => null]);

        foreach ($scored->dimensions as $d) {
            $this->assertGreaterThanOrEqual(0, $d->pointsAwarded);
        }
        $this->assertSame(0, $scored->dimension('geography_fit')->pointsAwarded);
        $this->assertSame(0, $scored->dimension('industry_fit')->pointsAwarded);
        $this->assertSame(ScorePriority::Low, $scored->priority);
        $this->assertContains($scored->dimension('geography_fit')->note, ['unknown', 'not requested']);
    }

    public function test_not_satisfied_and_conflicting_never_earn_fit_points(): void
    {
        $notSat = $this->score([
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_LOCATION, CriterionKind::Hard, 'Cebu City'), CriterionResult::NotSatisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_CONTRADICTION, 'Different location.', EvidenceStrength::Direct)]),
        ], QualificationOutcome::WeakMatch, ['location' => null]);
        $this->assertSame(0, $notSat->dimension('geography_fit')->pointsAwarded);
        $this->assertSame('not satisfied', $notSat->dimension('geography_fit')->note);

        $conflicting = $this->score([
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_INDUSTRY, CriterionKind::Hard, 'cosmetics'), CriterionResult::Conflicting,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_PRODUCT, 'cosmetics', EvidenceStrength::Direct)]),
        ], QualificationOutcome::WeakMatch, ['category' => null]);
        $this->assertSame(0, $conflicting->dimension('industry_fit')->pointsAwarded);
        $this->assertSame('conflicting', $conflicting->dimension('industry_fit')->note);
    }

    public function test_a_website_alone_is_not_online_selling(): void
    {
        $scored = $this->score([
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_LOCATION, CriterionKind::Hard, 'Cebu City'), CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_LOCATION, 'cebu', EvidenceStrength::Direct)]),
        ], QualificationOutcome::PossibleMatch, [
            'website' => 'https://abcbeauty.test/', 'domain' => 'abcbeauty.test',
            'onlineSellingEvidence' => false, 'observedProducts' => [], 'category' => null, 'evidence' => [],
        ]);

        $this->assertSame(0, $scored->dimension('online_selling')->pointsAwarded);
        $this->assertStringContainsString('website alone is not online selling', $scored->dimension('online_selling')->reason);
    }

    public function test_a_service_business_is_not_scored_high_for_physical_product_relevance(): void
    {
        $scored = $this->score([], QualificationOutcome::PossibleMatch, [
            'category' => 'management consulting services',
            'observedProducts' => [], 'evidence' => [],
        ]);

        $this->assertSame(0, $scored->dimension('physical_product_relevance')->pointsAwarded);
        $this->assertStringContainsString('does not clearly indicate physical', (string) $scored->dimension('physical_product_relevance')->note);
    }

    public function test_missing_shipping_evidence_earns_no_points_and_stays_in_missing_information(): void
    {
        $scored = $this->score([
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_SHIPPING, CriterionKind::Hard), CriterionResult::Unknown),
        ], QualificationOutcome::PossibleMatch, ['shippingEvidence' => false]);

        $this->assertSame(0, $scored->dimension('shipping_signals')->pointsAwarded);
        $this->assertTrue(collect($scored->toArray()['missing_information'])->contains(fn ($m) => str_contains(strtolower($m), 'courier')));
    }

    public function test_evidence_quality_is_small_and_never_dominates(): void
    {
        $scored = $this->score([
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_LOCATION, CriterionKind::Hard, 'Cebu City'), CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_LOCATION, 'cebu', EvidenceStrength::Direct)]),
        ], QualificationOutcome::PossibleMatch, ['confidence' => 'low']);

        $eq = $scored->dimension('evidence_quality');
        $this->assertLessThanOrEqual(5, $eq->pointsAwarded);
        $this->assertSame(5, $eq->maxPoints);
    }

    public function test_duplicated_evidence_does_not_change_the_score(): void
    {
        $one = ProspectFixtures::evidence(EvidenceItem::TYPE_ONLINE_SELLING, 'checkout', EvidenceStrength::Direct);
        $criterion = ProspectFixtures::criterion(QualificationCriterion::KEY_ONLINE_SELLING, CriterionKind::Hard);

        $single = $this->score([
            ProspectFixtures::evaluation($criterion, CriterionResult::Satisfied, [$one]),
        ], QualificationOutcome::PossibleMatch, ['evidence' => [$one], 'onlineSellingEvidence' => true]);

        $tripled = $this->score([
            ProspectFixtures::evaluation($criterion, CriterionResult::Satisfied, [$one, $one, $one]),
        ], QualificationOutcome::PossibleMatch, ['evidence' => [$one, $one, $one], 'onlineSellingEvidence' => true]);

        $this->assertSame($single->totalScore, $tripled->totalScore);
        $this->assertSame(
            $single->dimension('online_selling')->pointsAwarded,
            $tripled->dimension('online_selling')->pointsAwarded,
        );
        $this->assertLessThanOrEqual(6, count($tripled->dimension('online_selling')->evidence));
    }

    public function test_the_score_is_identical_for_identical_input(): void
    {
        $c = $this->strongCandidate();
        $evals = [
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_LOCATION, CriterionKind::Hard, 'Cebu City'), CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_LOCATION, 'cebu', EvidenceStrength::Direct)]),
        ];

        $a = $this->service()->scoreProspect(ProspectFixtures::qualified($evals, QualificationOutcome::StrongMatch, $c), $this->model());
        $b = $this->service()->scoreProspect(ProspectFixtures::qualified($evals, QualificationOutcome::StrongMatch, $c), $this->model());

        $this->assertEquals($a->toArray(), $b->toArray());
    }

    public function test_the_total_is_always_between_0_and_100(): void
    {
        foreach ([QualificationOutcome::StrongMatch, QualificationOutcome::WeakMatch, QualificationOutcome::InsufficientEvidence] as $outcome) {
            $scored = $this->score([], $outcome, $this->strongCandidate());
            $this->assertGreaterThanOrEqual(0, $scored->totalScore);
            $this->assertLessThanOrEqual(100, $scored->totalScore);
            $this->assertLessThanOrEqual(100, $scored->rawScore);
        }
    }

    public function test_qualification_outcome_caps_the_score(): void
    {
        $bigEvals = [
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_LOCATION, CriterionKind::Hard, 'Cebu City'), CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_LOCATION, 'cebu', EvidenceStrength::Direct)]),
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_INDUSTRY, CriterionKind::Hard, 'cosmetics'), CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_PRODUCT, 'cosmetics', EvidenceStrength::Direct)]),
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_ONLINE_SELLING, CriterionKind::Hard), CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_ONLINE_SELLING, 'checkout', EvidenceStrength::Direct)]),
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_SHIPPING, CriterionKind::Hard), CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_SHIPPING, 'nationwide', EvidenceStrength::Direct)]),
        ];
        $c = $this->strongCandidate();

        $weak = $this->service()->scoreProspect(ProspectFixtures::qualified($bigEvals, QualificationOutcome::WeakMatch, $c), $this->model());
        $this->assertLessThanOrEqual(55, $weak->totalScore);
        $this->assertNotNull($weak->cappedBy);
        $this->assertStringContainsString('WEAK MATCH', $weak->cappedBy);

        $insufficient = $this->service()->scoreProspect(ProspectFixtures::qualified($bigEvals, QualificationOutcome::InsufficientEvidence, $c), $this->model());
        $this->assertLessThanOrEqual(35, $insufficient->totalScore);
        $this->assertSame(ScorePriority::Low, $insufficient->priority);
    }

    public function test_the_output_exposes_the_scoring_version_and_a_full_breakdown(): void
    {
        $array = $this->score([], QualificationOutcome::PossibleMatch, $this->strongCandidate())->toArray();

        $this->assertSame('v2.3-default-1', $array['scoring_model']);
        $this->assertCount(7, $array['breakdown']);
        $this->assertSame(100, $array['max_score']);
        $this->assertArrayHasKey('priority', $array);
        $this->assertArrayHasKey('raw_score', $array);
        $this->assertArrayHasKey('identity', $array);
        $this->assertSame('abcbeauty.test', $array['identity']['domain']);

        foreach ($array['breakdown'] as $dimension) {
            $this->assertArrayHasKey('key', $dimension);
            $this->assertArrayHasKey('points_awarded', $dimension);
            $this->assertArrayHasKey('max_points', $dimension);
            $this->assertArrayHasKey('reason', $dimension);
        }
    }

    public function test_ranking_is_deterministic_with_defined_tie_breaking(): void
    {
        $high = $this->score([
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_LOCATION, CriterionKind::Hard, 'Cebu City'), CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_LOCATION, 'cebu', EvidenceStrength::Direct)]),
            ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_INDUSTRY, CriterionKind::Hard, 'cosmetics'), CriterionResult::Satisfied,
                [ProspectFixtures::evidence(EvidenceItem::TYPE_PRODUCT, 'cosmetics', EvidenceStrength::Direct)]),
        ], QualificationOutcome::StrongMatch, $this->strongCandidate(['domain' => 'zeta.test']));

        $low = $this->score([], QualificationOutcome::InsufficientEvidence, ['website' => null, 'domain' => 'alpha.test', 'category' => null, 'evidence' => []]);

        $ranked = $this->service()->rank([$low, $high]);
        $this->assertSame('zeta.test', $ranked[0]->prospect->candidate->domain);
        $this->assertSame('alpha.test', $ranked[1]->prospect->candidate->domain);

        // Tie on total + outcome + evidence quality → alphabetical domain.
        $tieA = $this->score([], QualificationOutcome::WeakMatch, ['website' => null, 'domain' => 'bravo.test', 'category' => null, 'evidence' => []]);
        $tieB = $this->score([], QualificationOutcome::WeakMatch, ['website' => null, 'domain' => 'alpha.test', 'category' => null, 'evidence' => []]);
        $rankedTie = $this->service()->rank([$tieA, $tieB]);
        $this->assertSame('alpha.test', $rankedTie[0]->prospect->candidate->domain);
    }

    public function test_the_scoring_core_never_touches_the_network(): void
    {
        Http::preventStrayRequests();
        $this->app->instance(SearchProvider::class, new class implements SearchProvider
        {
            public function search(string $query, int $limit): array
            {
                throw new \RuntimeException('scoring must not search');
            }

            public function name(): string
            {
                return 'forbidden';
            }
        });

        $scored = $this->service()->scoreProspect(
            ProspectFixtures::qualified([
                ProspectFixtures::evaluation(ProspectFixtures::criterion(QualificationCriterion::KEY_LOCATION, CriterionKind::Hard, 'Cebu City'), CriterionResult::Satisfied,
                    [ProspectFixtures::evidence(EvidenceItem::TYPE_LOCATION, 'cebu', EvidenceStrength::Direct)]),
            ], QualificationOutcome::StrongMatch, $this->strongCandidate()),
            $this->model(),
        );

        $this->assertGreaterThan(0, $scored->totalScore);
    }
}
