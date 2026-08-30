<?php

namespace Tests\Feature\MarketIntelligence;

use App\Support\MarketIntelligence\QualificationOutcome;
use App\Support\MarketIntelligence\ScorePriority;
use App\Support\MarketIntelligence\ScoringModel;
use Tests\TestCase;

/**
 * V2.3 (spec §5/§18/§19/§20): the scoring model is config-backed,
 * validated on load, and falls back to frozen defaults on anything
 * malformed — scoring can never run on a broken model.
 */
class ProspectScoringModelTest extends TestCase
{
    public function test_the_default_model_totals_exactly_100(): void
    {
        $model = ScoringModel::default();

        $this->assertSame(100, $model->maxScore());
        $this->assertSame(100, array_sum($model->weights));
        $this->assertTrue($model->isValid());
        $this->assertTrue($model->configValid);
        $this->assertSame('v2.3-default-1', $model->version);
    }

    public function test_from_config_reads_the_default_config(): void
    {
        $model = ScoringModel::fromConfig();

        $this->assertSame(100, $model->maxScore());
        $this->assertTrue($model->configValid);
        $this->assertSame(20, $model->weightFor('industry_fit'));
        $this->assertSame(5, $model->weightFor('evidence_quality'));
    }

    public function test_weights_that_do_not_total_100_fall_back_to_defaults(): void
    {
        config(['services.market_intelligence.scoring.weights.industry_fit' => 40]);

        $model = ScoringModel::fromConfig();

        $this->assertFalse($model->configValid);
        $this->assertSame(100, $model->maxScore());
        $this->assertSame(ScoringModel::DEFAULT_WEIGHTS, $model->weights);
    }

    public function test_a_negative_weight_falls_back_to_defaults(): void
    {
        config(['services.market_intelligence.scoring.weights.geography_fit' => -5]);

        $model = ScoringModel::fromConfig();

        $this->assertFalse($model->configValid);
        $this->assertSame(ScoringModel::DEFAULT_WEIGHTS, $model->weights);
    }

    public function test_overlapping_priority_bands_fall_back_to_defaults(): void
    {
        config([
            'services.market_intelligence.scoring.bands.high' => 40,
            'services.market_intelligence.scoring.bands.medium' => 60,
        ]);

        $model = ScoringModel::fromConfig();

        $this->assertFalse($model->configValid);
        $this->assertSame(ScoringModel::DEFAULT_BANDS, $model->bands);
    }

    public function test_non_monotonic_outcome_caps_fall_back_to_defaults(): void
    {
        config(['services.market_intelligence.scoring.outcome_caps.weak_match' => 95]);

        $model = ScoringModel::fromConfig();

        $this->assertFalse($model->configValid);
        $this->assertSame(ScoringModel::DEFAULT_OUTCOME_CAPS, $model->outcomeCaps);
    }

    public function test_valid_custom_weights_are_honoured(): void
    {
        config([
            'services.market_intelligence.scoring.weights' => [
                'industry_fit' => 25,
                'geography_fit' => 20,
                'online_selling' => 20,
                'physical_product_relevance' => 15,
                'shipping_signals' => 10,
                'digital_activity' => 5,
                'evidence_quality' => 5,
            ],
        ]);

        $model = ScoringModel::fromConfig();

        $this->assertTrue($model->configValid);
        $this->assertSame(25, $model->weightFor('industry_fit'));
        $this->assertSame(100, $model->maxScore());
    }

    public function test_priority_bands_have_no_gap_or_overlap(): void
    {
        $model = ScoringModel::default();

        $this->assertSame(ScorePriority::Low, $model->bandFor(0));
        $this->assertSame(ScorePriority::Low, $model->bandFor(49));
        $this->assertSame(ScorePriority::Medium, $model->bandFor(50));
        $this->assertSame(ScorePriority::Medium, $model->bandFor(74));
        $this->assertSame(ScorePriority::High, $model->bandFor(75));
        $this->assertSame(ScorePriority::High, $model->bandFor(100));
    }

    public function test_outcome_caps_are_a_ceiling_per_qualification_outcome(): void
    {
        $model = ScoringModel::default();

        $this->assertSame(100, $model->capFor(QualificationOutcome::StrongMatch));
        $this->assertSame(85, $model->capFor(QualificationOutcome::PossibleMatch));
        $this->assertSame(55, $model->capFor(QualificationOutcome::WeakMatch));
        $this->assertSame(35, $model->capFor(QualificationOutcome::InsufficientEvidence));
    }

    public function test_toarray_exposes_the_model_for_the_breakdown(): void
    {
        $array = ScoringModel::default()->toArray();

        $this->assertSame('v2.3-default-1', $array['version']);
        $this->assertSame(100, $array['max_score']);
        $this->assertArrayHasKey('weights', $array);
        $this->assertArrayHasKey('priority_bands', $array);
        $this->assertSame('75-100', $array['priority_bands']['high']);
        $this->assertSame('0-49', $array['priority_bands']['low']);
        $this->assertTrue($array['config_valid']);
    }
}
