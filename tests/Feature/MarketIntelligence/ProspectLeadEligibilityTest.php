<?php

namespace Tests\Feature\MarketIntelligence;

use App\Enums\ProspectLeadEligibility;
use Tests\TestCase;

/**
 * V2.5 (spec §6/§35): creation eligibility is a deterministic function
 * of the V2.4 duplicate-check result — never the score, the priority,
 * the qualification outcome, or the LLM.
 */
class ProspectLeadEligibilityTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function test_the_eligibility_state_machine(string $checkStatus, ?string $duplicateStatus, ProspectLeadEligibility $expected): void
    {
        $this->assertSame($expected, ProspectLeadEligibility::forCheck($checkStatus, $duplicateStatus));
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: ProspectLeadEligibility}>
     */
    public static function cases(): array
    {
        return [
            'A: ok + no_match' => ['ok', 'no_match', ProspectLeadEligibility::EligibleForConfirmation],
            'B: ok + possible_duplicate' => ['ok', 'possible_duplicate', ProspectLeadEligibility::ReviewRequired],
            'C: ok + likely_duplicate' => ['ok', 'likely_duplicate', ProspectLeadEligibility::BlockedDuplicate],
            'D: ok + exact_duplicate' => ['ok', 'exact_duplicate', ProspectLeadEligibility::BlockedDuplicate],
            'E: unavailable' => ['unavailable', null, ProspectLeadEligibility::BlockedCheckUnavailable],
            'F: skipped' => ['skipped', null, ProspectLeadEligibility::BlockedInsufficientIdentity],
            'ok + null (defensive)' => ['ok', null, ProspectLeadEligibility::BlockedCheckUnavailable],
            'garbage check status' => ['banana', 'no_match', ProspectLeadEligibility::BlockedCheckUnavailable],
        ];
    }

    public function test_only_eligible_and_review_required_can_reach_confirmation(): void
    {
        $this->assertTrue(ProspectLeadEligibility::EligibleForConfirmation->canReachConfirmation());
        $this->assertTrue(ProspectLeadEligibility::ReviewRequired->canReachConfirmation());
        $this->assertFalse(ProspectLeadEligibility::BlockedDuplicate->canReachConfirmation());
        $this->assertFalse(ProspectLeadEligibility::BlockedCheckUnavailable->canReachConfirmation());
        $this->assertFalse(ProspectLeadEligibility::BlockedInsufficientIdentity->canReachConfirmation());
    }

    public function test_only_review_required_needs_a_duplicate_acknowledgement(): void
    {
        $this->assertTrue(ProspectLeadEligibility::ReviewRequired->requiresDuplicateAcknowledgement());
        $this->assertFalse(ProspectLeadEligibility::EligibleForConfirmation->requiresDuplicateAcknowledgement());
        $this->assertFalse(ProspectLeadEligibility::BlockedDuplicate->requiresDuplicateAcknowledgement());
    }

    public function test_a_possible_duplicate_is_never_silently_treated_as_no_match(): void
    {
        $this->assertNotSame(
            ProspectLeadEligibility::forCheck('ok', 'no_match'),
            ProspectLeadEligibility::forCheck('ok', 'possible_duplicate'),
        );
    }
}
