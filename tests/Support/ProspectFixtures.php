<?php

namespace Tests\Support;

use App\Support\MarketIntelligence\EvidenceItem;
use App\Support\MarketIntelligence\EvidenceStrength;
use App\Support\MarketIntelligence\ProspectCandidate;
use App\Support\MarketIntelligence\SourceQuality;

/**
 * Deterministic builders for V2.2 qualification tests — hand-built
 * ProspectCandidates and EvidenceItems, no network.
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
}
