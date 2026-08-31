<?php

namespace Tests\Support;

use App\Support\MarketIntelligence\CriterionEvaluation;
use App\Support\MarketIntelligence\CriterionKind;
use App\Support\MarketIntelligence\CriterionResult;
use App\Support\MarketIntelligence\CrmOrganizationIdentity;
use App\Support\MarketIntelligence\EvidenceItem;
use App\Support\MarketIntelligence\EvidenceStrength;
use App\Support\MarketIntelligence\ProspectCandidate;
use App\Support\MarketIntelligence\ProspectIdentity;
use App\Support\MarketIntelligence\QualificationCriterion;
use App\Support\MarketIntelligence\QualificationOutcome;
use App\Support\MarketIntelligence\QualifiedProspect;
use App\Support\MarketIntelligence\SourceQuality;

/**
 * Deterministic builders for V2.2 / V2.3 tests — hand-built
 * ProspectCandidates, EvidenceItems, CriterionEvaluations and
 * QualifiedProspects. No network.
 */
class ProspectFixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function candidate(array $overrides = []): ProspectCandidate
    {
        $defaults = [
            'name' => 'ABC Beauty Store',
            'website' => 'https://abcbeauty.test/',
            'domain' => 'abcbeauty.test',
            'location' => null,
            'category' => null,
            'observedProducts' => [],
            'onlineSellingEvidence' => false,
            'shippingEvidence' => false,
            'socialPresence' => [],
            'evidence' => [],
            'missing' => [],
            'confidence' => ProspectCandidate::CONFIDENCE_MEDIUM,
            'recommendedNextStep' => 'Review the website.',
        ];

        $v = array_merge($defaults, $overrides);

        return new ProspectCandidate(
            name: $v['name'],
            website: $v['website'],
            domain: $v['domain'],
            location: $v['location'],
            category: $v['category'],
            observedProducts: $v['observedProducts'],
            onlineSellingEvidence: $v['onlineSellingEvidence'],
            shippingEvidence: $v['shippingEvidence'],
            socialPresence: $v['socialPresence'],
            evidence: $v['evidence'],
            missing: $v['missing'],
            confidence: $v['confidence'],
            recommendedNextStep: $v['recommendedNextStep'],
        );
    }

    public static function evidence(
        string $type,
        string $summary,
        EvidenceStrength $strength = EvidenceStrength::Direct,
        string $domain = 'abcbeauty.test',
        SourceQuality $quality = SourceQuality::OfficialCompany,
    ): EvidenceItem {
        return new EvidenceItem(
            $type,
            $summary,
            'https://'.$domain.'/about',
            $domain,
            '2026-08-01T00:00:00+00:00',
            $strength,
            $quality,
        );
    }

    public static function criterion(string $key, CriterionKind $kind = CriterionKind::Hard, ?string $expected = null): QualificationCriterion
    {
        return new QualificationCriterion($key, $kind, ucwords(str_replace('_', ' ', $key)), $expected);
    }

    /**
     * @param  list<EvidenceItem>  $evidence
     */
    public static function evaluation(
        QualificationCriterion $criterion,
        CriterionResult $result = CriterionResult::Satisfied,
        array $evidence = [],
        string $claim = 'criterion claim',
    ): CriterionEvaluation {
        return new CriterionEvaluation($criterion, $result, $claim, $evidence);
    }

    /**
     * @param  list<CriterionEvaluation>  $evaluations
     * @param  array<string, mixed>  $candidateOverrides
     */
    public static function qualified(
        array $evaluations,
        QualificationOutcome $outcome = QualificationOutcome::StrongMatch,
        array $candidateOverrides = [],
        array $missing = ['Actual shipment / parcel volume', 'Current or incumbent courier / logistics provider'],
    ): QualifiedProspect {
        return new QualifiedProspect(
            candidate: self::candidate($candidateOverrides),
            outcome: $outcome,
            evaluations: $evaluations,
            observed: ['Location: Cebu City'],
            inferences: ['Selling physical products online creates a plausible parcel-delivery requirement.'],
            missing: $missing,
            recommendation: 'Worth further business-development research.',
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function prospectIdentity(array $overrides = []): ProspectIdentity
    {
        $v = array_merge([
            'business' => 'ABC Beauty Corporation',
            'website' => 'https://abcbeauty.ph/',
            'domain' => 'abcbeauty.ph',
            'location' => 'Cebu City, Philippines',
            'public_profiles' => [],
        ], $overrides);

        return new ProspectIdentity(
            business: $v['business'],
            website: $v['website'],
            domain: $v['domain'],
            location: $v['location'],
            publicProfiles: $v['public_profiles'],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function crmOrganization(array $overrides = []): CrmOrganizationIdentity
    {
        $v = array_merge([
            'id' => 1,
            'name' => 'ABC Beauty Corp.',
            'website' => 'https://www.abcbeauty.ph/',
            'email' => null,
            'city' => 'Cebu City',
            'state_province' => null,
            'country' => 'Philippines',
            'has_lead' => false,
            'has_opportunity' => false,
        ], $overrides);

        return new CrmOrganizationIdentity(
            id: $v['id'],
            name: $v['name'],
            website: $v['website'],
            email: $v['email'],
            city: $v['city'],
            stateProvince: $v['state_province'],
            country: $v['country'],
            hasLead: $v['has_lead'],
            hasOpportunity: $v['has_opportunity'],
        );
    }
}
