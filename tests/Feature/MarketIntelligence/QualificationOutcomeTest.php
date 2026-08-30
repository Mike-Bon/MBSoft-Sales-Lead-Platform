<?php

namespace Tests\Feature\MarketIntelligence;

use App\Services\MarketIntelligence\ProspectQualificationService;
use App\Support\MarketIntelligence\CriterionKind;
use App\Support\MarketIntelligence\CriterionResult;
use App\Support\MarketIntelligence\EvidenceItem;
use App\Support\MarketIntelligence\EvidenceStrength;
use App\Support\MarketIntelligence\QualificationCriteria;
use App\Support\MarketIntelligence\QualificationCriterion;
use App\Support\MarketIntelligence\QualificationOutcome;
use Tests\Support\ProspectFixtures;
use Tests\TestCase;

/**
 * V2.2 (spec §8/§9/§13): the qualification decision is DETERMINISTIC and
 * decided by the application, not the LLM. These tests drive the pure
 * core (evaluate(), no network) against hand-built candidates and pin
 * the decision table.
 */
class QualificationOutcomeTest extends TestCase
{
    private function service(): ProspectQualificationService
    {
        return app(ProspectQualificationService::class);
    }

    private function hard(string $key, ?string $expected = null): QualificationCriterion
    {
        return new QualificationCriterion($key, CriterionKind::Hard, ucfirst($key), $expected);
    }

    private function supporting(string $key, ?string $expected = null): QualificationCriterion
    {
        return new QualificationCriterion($key, CriterionKind::Supporting, ucfirst($key), $expected);
    }

    /**
     * @param  list<QualificationCriterion>  $criteria
     */
    private function criteria(array $criteria): QualificationCriteria
    {
        return new QualificationCriteria($criteria, 8);
    }

    public function test_all_hard_criteria_satisfied_by_direct_evidence_is_a_strong_match(): void
    {
        $candidate = ProspectFixtures::candidate([
            'location' => 'Cebu City',
            'category' => 'cosmetics',
            'observedProducts' => ['lipstick'],
            'evidence' => [
                ProspectFixtures::evidence(EvidenceItem::TYPE_LOCATION, 'Source names the area "cebu".', EvidenceStrength::Direct),
                ProspectFixtures::evidence(EvidenceItem::TYPE_PRODUCT, 'Source text mentions "cosmetics".', EvidenceStrength::Direct),
            ],
        ]);

        $result = $this->service()->evaluate($candidate, $this->criteria([
            $this->hard(QualificationCriterion::KEY_LOCATION, 'Cebu City'),
            $this->hard(QualificationCriterion::KEY_INDUSTRY, 'cosmetics'),
        ]));

        $this->assertSame(QualificationOutcome::StrongMatch, $result->outcome);
        foreach ($result->hardEvaluations() as $evaluation) {
            $this->assertSame(CriterionResult::Satisfied, $evaluation->result);
            $this->assertNotEmpty($evaluation->evidence, 'A satisfied criterion must carry its evidence.');
        }
    }

    public function test_a_hard_criterion_met_only_by_indirect_evidence_is_a_possible_match(): void
    {
        $candidate = ProspectFixtures::candidate([
            'location' => 'Cebu City',
            'evidence' => [
                ProspectFixtures::evidence(EvidenceItem::TYPE_LOCATION, 'Source names the area "cebu".', EvidenceStrength::Indirect),
            ],
        ]);

        $result = $this->service()->evaluate($candidate, $this->criteria([
            $this->hard(QualificationCriterion::KEY_LOCATION, 'Cebu City'),
        ]));

        $this->assertSame(QualificationOutcome::PossibleMatch, $result->outcome);
    }

    public function test_a_failed_hard_criterion_forces_at_most_a_weak_match_despite_supporting_signals(): void
    {
        $candidate = ProspectFixtures::candidate([
            'onlineSellingEvidence' => true,
            'shippingEvidence' => true,
            'socialPresence' => ['https://facebook.com/abcbeauty'],
            'evidence' => [
                ProspectFixtures::evidence(EvidenceItem::TYPE_CONTRADICTION, 'Source names a different location, "davao", and does not mention Cebu City.', EvidenceStrength::Direct),
                ProspectFixtures::evidence(EvidenceItem::TYPE_ONLINE_SELLING, 'Source contains an online-selling indicator ("checkout").', EvidenceStrength::Direct),
                ProspectFixtures::evidence(EvidenceItem::TYPE_SHIPPING, 'Source mentions shipping.', EvidenceStrength::Direct),
            ],
        ]);

        $result = $this->service()->evaluate($candidate, $this->criteria([
            $this->hard(QualificationCriterion::KEY_LOCATION, 'Cebu City'),
            $this->supporting(QualificationCriterion::KEY_ONLINE_SELLING),
            $this->supporting(QualificationCriterion::KEY_SHIPPING),
            $this->supporting(QualificationCriterion::KEY_SOCIAL_PRESENCE),
        ]));

        $this->assertSame(QualificationOutcome::WeakMatch, $result->outcome);
        $this->assertSame(CriterionResult::NotSatisfied, $result->hardEvaluations()[0]->result);
    }

    public function test_conflicting_evidence_on_a_hard_criterion_prevents_a_strong_match_and_retains_both_sources(): void
    {
        $candidate = ProspectFixtures::candidate([
            'location' => 'Cebu City',
            'evidence' => [
                ProspectFixtures::evidence(EvidenceItem::TYPE_LOCATION, 'Source names the area "cebu".', EvidenceStrength::Direct),
                ProspectFixtures::evidence(EvidenceItem::TYPE_CONTRADICTION, 'Source names a different location, "davao".', EvidenceStrength::Corroborating, 'directory.test'),
            ],
        ]);

        $result = $this->service()->evaluate($candidate, $this->criteria([
            $this->hard(QualificationCriterion::KEY_LOCATION, 'Cebu City'),
        ]));

        $this->assertSame(QualificationOutcome::WeakMatch, $result->outcome);
        $evaluation = $result->hardEvaluations()[0];
        $this->assertSame(CriterionResult::Conflicting, $evaluation->result);
        $this->assertCount(2, $evaluation->evidence, 'Both conflicting sources must be retained.');
    }

    public function test_all_hard_criteria_unknown_is_insufficient_evidence(): void
    {
        $candidate = ProspectFixtures::candidate();

        $result = $this->service()->evaluate($candidate, $this->criteria([
            $this->hard(QualificationCriterion::KEY_LOCATION, 'Cebu City'),
            $this->hard(QualificationCriterion::KEY_SHIPPING),
        ]));

        $this->assertSame(QualificationOutcome::InsufficientEvidence, $result->outcome);
        foreach ($result->hardEvaluations() as $evaluation) {
            $this->assertSame(CriterionResult::Unknown, $evaluation->result);
        }
    }

    public function test_absence_of_shipping_evidence_is_unknown_not_not_satisfied(): void
    {
        $candidate = ProspectFixtures::candidate();

        $result = $this->service()->evaluate($candidate, $this->criteria([
            $this->supporting(QualificationCriterion::KEY_SHIPPING),
        ]));

        $this->assertSame(CriterionResult::Unknown, $result->supportingEvaluations()[0]->result);
        $this->assertNotSame(CriterionResult::NotSatisfied, $result->supportingEvaluations()[0]->result);
    }

    public function test_no_website_plus_social_only_makes_the_own_website_criterion_not_satisfied(): void
    {
        $candidate = ProspectFixtures::candidate([
            'website' => null,
            'domain' => 'facebook.com',
            'socialPresence' => ['https://facebook.com/abcbeauty'],
            'evidence' => [
                ProspectFixtures::evidence(EvidenceItem::TYPE_SOCIAL_PRESENCE, 'A public social/business profile link was found.', EvidenceStrength::Corroborating, 'facebook.com'),
            ],
        ]);

        $result = $this->service()->evaluate($candidate, $this->criteria([
            $this->hard(QualificationCriterion::KEY_OWN_WEBSITE),
        ]));

        $this->assertSame(CriterionResult::NotSatisfied, $result->hardEvaluations()[0]->result);
        $this->assertSame(QualificationOutcome::WeakMatch, $result->outcome);
    }

    public function test_a_majority_of_unknown_hard_criteria_is_insufficient_even_with_one_strong(): void
    {
        $candidate = ProspectFixtures::candidate([
            'location' => 'Cebu City',
            'evidence' => [
                ProspectFixtures::evidence(EvidenceItem::TYPE_LOCATION, 'Source names the area "cebu".', EvidenceStrength::Direct),
            ],
        ]);

        $result = $this->service()->evaluate($candidate, $this->criteria([
            $this->hard(QualificationCriterion::KEY_LOCATION, 'Cebu City'),
            $this->hard(QualificationCriterion::KEY_SHIPPING),
            $this->hard(QualificationCriterion::KEY_MARKETPLACE),
        ]));

        $this->assertSame(QualificationOutcome::InsufficientEvidence, $result->outcome);
    }

    public function test_supporting_only_criteria_can_only_reach_possible_match(): void
    {
        $satisfied = ProspectFixtures::candidate([
            'onlineSellingEvidence' => true,
            'evidence' => [ProspectFixtures::evidence(EvidenceItem::TYPE_ONLINE_SELLING, 'checkout', EvidenceStrength::Direct)],
        ]);
        $none = ProspectFixtures::candidate();

        $criteria = $this->criteria([$this->supporting(QualificationCriterion::KEY_ONLINE_SELLING)]);

        $this->assertSame(QualificationOutcome::PossibleMatch, $this->service()->evaluate($satisfied, $criteria)->outcome);
        $this->assertSame(QualificationOutcome::InsufficientEvidence, $this->service()->evaluate($none, $criteria)->outcome);
    }

    public function test_the_qualified_prospect_array_is_the_v2_3_handoff_shape(): void
    {
        $candidate = ProspectFixtures::candidate([
            'location' => 'Cebu City',
            'category' => 'cosmetics',
            'onlineSellingEvidence' => true,
            'observedProducts' => ['skincare'],
            'confidence' => 'high',
            'evidence' => [
                ProspectFixtures::evidence(EvidenceItem::TYPE_LOCATION, 'Source names the area "cebu".', EvidenceStrength::Direct),
                ProspectFixtures::evidence(EvidenceItem::TYPE_ONLINE_SELLING, 'checkout', EvidenceStrength::Direct),
            ],
        ]);

        $array = $this->service()->evaluate($candidate, $this->criteria([
            $this->hard(QualificationCriterion::KEY_LOCATION, 'Cebu City'),
            $this->supporting(QualificationCriterion::KEY_ONLINE_SELLING),
        ]))->toArray();

        foreach (['business', 'qualification_outcome', 'qualification_outcome_label', 'hard_criteria', 'supporting_signals', 'observed', 'inference', 'missing_information', 'recommendation', 'discovery_confidence', 'sources'] as $key) {
            $this->assertArrayHasKey($key, $array);
        }

        $this->assertSame('high', $array['discovery_confidence']);
        $this->assertNotSame($array['discovery_confidence'], $array['qualification_outcome'], 'Discovery confidence and qualification outcome are different concepts.');

        // Every hard-criterion entry carries a machine-readable claim → evidence link.
        foreach ($array['hard_criteria'] as $entry) {
            $this->assertArrayHasKey('claim', $entry);
            $this->assertArrayHasKey('evidence', $entry);
            $this->assertArrayHasKey('evidence_strength', $entry);
        }

        // Qualification blind spots are always surfaced as missing (spec §17).
        $this->assertTrue(collect($array['missing_information'])->contains(fn ($m) => str_contains(strtolower($m), 'courier')));
        $this->assertTrue(collect($array['missing_information'])->contains(fn ($m) => str_contains(strtolower($m), 'volume')));

        // No numeric score anywhere.
        $this->assertStringNotContainsString('score', strtolower(json_encode($array)));
    }
}
