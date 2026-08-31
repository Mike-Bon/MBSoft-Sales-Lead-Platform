<?php

namespace Tests\Feature\MarketIntelligence;

use App\Services\MarketIntelligence\ProspectDuplicateMatcher;
use App\Support\MarketIntelligence\DuplicateMatchPolicy;
use App\Support\MarketIntelligence\DuplicateStatus;
use App\Support\MarketIntelligence\MatchSignal;
use Tests\Support\ProspectFixtures;
use Tests\TestCase;

/**
 * V2.4 (spec §10–§16, §22, §34): the PURE duplicate-matching core —
 * deterministic, no database, no network, no LLM. Driven against
 * hand-built identities.
 */
class ProspectDuplicateMatcherTest extends TestCase
{
    private function matcher(): ProspectDuplicateMatcher
    {
        return new ProspectDuplicateMatcher;
    }

    private function policy(): DuplicateMatchPolicy
    {
        return DuplicateMatchPolicy::default();
    }

    public function test_exact_domain_plus_compatible_name_is_an_exact_duplicate(): void
    {
        $result = $this->matcher()->match(
            ProspectFixtures::prospectIdentity(['business' => 'ABC Beauty Corporation', 'domain' => 'abcbeauty.ph']),
            [ProspectFixtures::crmOrganization(['name' => 'ABC Beauty Corp.', 'website' => 'https://www.abcbeauty.ph/'])],
            $this->policy(),
        );

        $this->assertSame(DuplicateStatus::ExactDuplicate, $result['status']);
        $candidate = $result['candidates'][0];
        $signalKeys = array_map(fn (MatchSignal $s) => $s->key, $candidate->signals);
        $this->assertContains(MatchSignal::KEY_DOMAIN_EXACT, $signalKeys);
        $this->assertContains(MatchSignal::KEY_NAME_EXACT, $signalKeys);
        // Both compared values are shown for human verification.
        $domainSignal = collect($candidate->signals)->firstWhere('key', MatchSignal::KEY_DOMAIN_EXACT);
        $this->assertSame('abcbeauty.ph', $domainSignal->prospectValue);
        $this->assertSame('abcbeauty.ph', $domainSignal->crmValue);
    }

    public function test_www_and_scheme_and_path_differences_still_match_the_domain(): void
    {
        $result = $this->matcher()->match(
            ProspectFixtures::prospectIdentity(['website' => 'http://abcbeauty.ph', 'domain' => null]),
            [ProspectFixtures::crmOrganization(['website' => 'https://www.abcbeauty.ph/products/lipstick'])],
            $this->policy(),
        );

        $this->assertSame(DuplicateStatus::ExactDuplicate, $result['status']);
    }

    public function test_exact_domain_but_totally_different_name_is_only_likely(): void
    {
        $result = $this->matcher()->match(
            ProspectFixtures::prospectIdentity(['business' => 'ABC Beauty', 'domain' => 'abcbeauty.ph']),
            [ProspectFixtures::crmOrganization(['name' => 'Zeta Hardware Trading', 'website' => 'https://abcbeauty.ph', 'city' => null, 'country' => null])],
            $this->policy(),
        );

        $this->assertSame(DuplicateStatus::LikelyDuplicate, $result['status']);
    }

    public function test_legal_suffix_and_punctuation_name_match_without_a_domain_is_possible(): void
    {
        $result = $this->matcher()->match(
            ProspectFixtures::prospectIdentity(['business' => 'ABC Beauty Corporation', 'website' => null, 'domain' => null, 'location' => null]),
            [ProspectFixtures::crmOrganization(['name' => 'ABC Beauty Corp.', 'website' => null, 'city' => null, 'country' => null])],
            $this->policy(),
        );

        $this->assertSame(DuplicateStatus::PossibleDuplicate, $result['status']);
    }

    public function test_distinctive_name_match_with_location_corroboration_is_likely(): void
    {
        $result = $this->matcher()->match(
            ProspectFixtures::prospectIdentity(['business' => 'Glow Radiance Skincare', 'website' => null, 'domain' => null, 'location' => 'Cebu City']),
            [ProspectFixtures::crmOrganization(['name' => 'Glow Radiance Skincare', 'website' => null, 'city' => 'Cebu City', 'country' => 'Philippines'])],
            $this->policy(),
        );

        $this->assertSame(DuplicateStatus::LikelyDuplicate, $result['status']);
    }

    public function test_fuzzy_name_only_is_at_most_possible(): void
    {
        $result = $this->matcher()->match(
            ProspectFixtures::prospectIdentity(['business' => 'Glow Radiance Skincare Store', 'website' => null, 'domain' => null, 'location' => null]),
            [ProspectFixtures::crmOrganization(['name' => 'Glow Radiance Skincare', 'website' => null, 'city' => null, 'country' => null])],
            $this->policy(),
        );

        $this->assertSame(DuplicateStatus::PossibleDuplicate, $result['status']);
        $this->assertContains(MatchSignal::KEY_NAME_FUZZY, array_map(fn (MatchSignal $s) => $s->key, $result['candidates'][0]->signals));
    }

    public function test_generic_short_names_do_not_produce_a_match_without_a_domain(): void
    {
        $result = $this->matcher()->match(
            ProspectFixtures::prospectIdentity(['business' => 'Online Store', 'website' => null, 'domain' => null, 'location' => 'Cebu City']),
            [ProspectFixtures::crmOrganization(['name' => 'Online Store', 'website' => null, 'city' => 'Cebu City'])],
            $this->policy(),
        );

        // Generic name + location is at most a weak POSSIBLE, never LIKELY/EXACT.
        $this->assertNotSame(DuplicateStatus::ExactDuplicate, $result['status']);
        $this->assertNotSame(DuplicateStatus::LikelyDuplicate, $result['status']);
    }

    public function test_similar_but_distinct_companies_do_not_match(): void
    {
        $result = $this->matcher()->match(
            ProspectFixtures::prospectIdentity(['business' => 'ABC Trading', 'website' => 'abctrading.ph', 'domain' => 'abctrading.ph', 'location' => 'Cebu City']),
            [ProspectFixtures::crmOrganization(['name' => 'ABC Trading Solutions', 'website' => 'https://abctradingsolutions.com', 'city' => 'Cebu City'])],
            $this->policy(),
        );

        $this->assertSame(DuplicateStatus::NoMatch, $result['status']);
        $this->assertSame([], $result['candidates']);
    }

    public function test_location_only_is_never_a_duplicate(): void
    {
        $result = $this->matcher()->match(
            ProspectFixtures::prospectIdentity(['business' => 'Cebu Cosmetics Distributors', 'website' => null, 'domain' => null, 'location' => 'Cebu City']),
            [ProspectFixtures::crmOrganization(['name' => 'Manila Glow Traders', 'website' => null, 'city' => 'Cebu City', 'country' => 'Philippines'])],
            $this->policy(),
        );

        $this->assertSame(DuplicateStatus::NoMatch, $result['status']);
    }

    public function test_no_identity_match_is_no_match(): void
    {
        $result = $this->matcher()->match(
            ProspectFixtures::prospectIdentity(['business' => 'Wholly Unrelated Widgets', 'domain' => 'unrelatedwidgets.example']),
            [ProspectFixtures::crmOrganization()],
            $this->policy(),
        );

        $this->assertSame(DuplicateStatus::NoMatch, $result['status']);
    }

    public function test_multiple_matches_are_returned_ordered_and_capped(): void
    {
        $identity = ProspectFixtures::prospectIdentity(['business' => 'ABC Beauty', 'domain' => 'abcbeauty.ph', 'location' => 'Cebu City']);
        $records = [
            ProspectFixtures::crmOrganization(['id' => 5, 'name' => 'ABC Beauty Philippines', 'website' => null, 'city' => 'Cebu City']),
            ProspectFixtures::crmOrganization(['id' => 3, 'name' => 'ABC Beauty Corp.', 'website' => 'https://www.abcbeauty.ph/']),
            ProspectFixtures::crmOrganization(['id' => 9, 'name' => 'ABC Beauty', 'website' => null, 'city' => 'Cebu City']),
        ];

        $result = $this->matcher()->match($identity, $records, DuplicateMatchPolicy::default());

        $this->assertSame(DuplicateStatus::ExactDuplicate, $result['status']);
        // Strongest (exact domain, id 3) first.
        $this->assertSame(3, $result['candidates'][0]->organizationId);
        $this->assertLessThanOrEqual(5, count($result['candidates']));
    }

    public function test_candidate_cap_is_respected(): void
    {
        config(['services.market_intelligence.duplicate_check.max_candidates_per_prospect' => 2]);
        $policy = DuplicateMatchPolicy::fromConfig();

        $records = [];
        for ($i = 1; $i <= 6; $i++) {
            $records[] = ProspectFixtures::crmOrganization(['id' => $i, 'name' => 'Glow Radiance Skincare', 'website' => null, 'city' => 'Cebu City']);
        }

        $result = $this->matcher()->match(
            ProspectFixtures::prospectIdentity(['business' => 'Glow Radiance Skincare', 'website' => null, 'domain' => null, 'location' => 'Cebu City']),
            $records,
            $policy,
        );

        $this->assertCount(2, $result['candidates']);
    }

    public function test_match_strength_is_internal_only_and_never_the_prospect_score(): void
    {
        $result = $this->matcher()->match(
            ProspectFixtures::prospectIdentity(['business' => 'ABC Beauty Corporation', 'domain' => 'abcbeauty.ph']),
            [ProspectFixtures::crmOrganization()],
            $this->policy(),
        );

        $array = $result['candidates'][0]->toArray();
        $this->assertArrayHasKey('match_strength', $array);
        $this->assertIsInt($array['match_strength']);
        $this->assertArrayNotHasKey('total_score', $array);
        $this->assertArrayNotHasKey('priority', $array);
        $this->assertArrayNotHasKey('lead_score', $array);
    }
}
