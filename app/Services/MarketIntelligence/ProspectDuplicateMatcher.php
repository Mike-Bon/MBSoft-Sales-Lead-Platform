<?php

namespace App\Services\MarketIntelligence;

use App\Support\MarketIntelligence\CrmOrganizationIdentity;
use App\Support\MarketIntelligence\DuplicateCandidate;
use App\Support\MarketIntelligence\DuplicateMatchPolicy;
use App\Support\MarketIntelligence\DuplicateStatus;
use App\Support\MarketIntelligence\IdentityNormalizer;
use App\Support\MarketIntelligence\MatchSignal;
use App\Support\MarketIntelligence\ProspectIdentity;

/**
 * V2.4 (spec §6/§10–§16/§21/§22): the PURE duplicate-matching core.
 *
 *   - NO database, NO Eloquent — it is handed a list of already-authorised
 *     CrmOrganizationIdentity value objects and compares identity fields.
 *   - NO network — SearchProvider / WebEvidenceFetcher / HTTP / DNS are
 *     never touched.
 *   - NO LLM — the classification comes from an explicit combination of
 *     deterministic signals, not a confidence number.
 *
 * Given the same ProspectIdentity + the same CRM identities + the same
 * policy, it always returns the same result.
 */
final class ProspectDuplicateMatcher
{
    /**
     * @param  list<CrmOrganizationIdentity>  $crmRecords  already scoped to the actor's authorisation
     * @return array{status: DuplicateStatus, candidates: list<DuplicateCandidate>}
     */
    public function match(ProspectIdentity $identity, array $crmRecords, DuplicateMatchPolicy $policy): array
    {
        $candidates = [];

        foreach ($crmRecords as $record) {
            $candidate = $this->classify($identity, $record, $policy);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, function (DuplicateCandidate $a, DuplicateCandidate $b) {
            return [$b->classification->rank(), $b->matchStrength]
                <=> [$a->classification->rank(), $a->matchStrength]
                ?: ($a->organizationId <=> $b->organizationId);
        });

        $candidates = array_slice($candidates, 0, $policy->maxCandidatesPerProspect);

        $status = $candidates === []
            ? DuplicateStatus::NoMatch
            : $candidates[0]->classification;

        return ['status' => $status, 'candidates' => array_values($candidates)];
    }

    private function classify(ProspectIdentity $identity, CrmOrganizationIdentity $record, DuplicateMatchPolicy $policy): ?DuplicateCandidate
    {
        $signals = $this->signals($identity, $record, $policy);

        $has = fn (string $key) => array_key_exists($key, $signals);

        $identityDistinctive = count($identity->distinctiveTokens());
        $recordDistinctive = count(IdentityNormalizer::distinctiveTokens($record->name));
        $bothDistinctive = $identityDistinctive >= $policy->minDistinctiveNameTokens
            && $recordDistinctive >= $policy->minDistinctiveNameTokens;

        $nameExactDistinctive = $has(MatchSignal::KEY_NAME_EXACT) && $bothDistinctive;
        $nameFuzzyDistinctive = $has(MatchSignal::KEY_NAME_FUZZY); // fuzzy already requires distinctiveness
        $nameCompatible = $has(MatchSignal::KEY_NAME_EXACT)
            || $has(MatchSignal::KEY_NAME_FUZZY)
            || $identity->nameTokens() === [];
        $corroborated = $has(MatchSignal::KEY_WEBSITE_EXACT)
            || $has(MatchSignal::KEY_EMAIL_DOMAIN)
            || $has(MatchSignal::KEY_LOCATION);

        $classification = match (true) {
            $has(MatchSignal::KEY_DOMAIN_EXACT) && $nameCompatible => DuplicateStatus::ExactDuplicate,
            $has(MatchSignal::KEY_DOMAIN_EXACT) => DuplicateStatus::LikelyDuplicate,
            $nameExactDistinctive && $corroborated => DuplicateStatus::LikelyDuplicate,
            $nameExactDistinctive => DuplicateStatus::PossibleDuplicate,
            $nameFuzzyDistinctive => DuplicateStatus::PossibleDuplicate,
            $has(MatchSignal::KEY_NAME_EXACT) && $corroborated => DuplicateStatus::PossibleDuplicate,
            default => null,
        };

        if ($classification === null) {
            return null;
        }

        return new DuplicateCandidate(
            organizationId: $record->id,
            name: $record->name,
            website: $record->website,
            domain: $record->normalizedHost(),
            location: $this->recordLocation($record),
            classification: $classification,
            matchStrength: $this->matchStrength($signals, $bothDistinctive),
            signals: array_values($signals),
            crmLinkage: ['has_lead' => $record->hasLead, 'has_opportunity' => $record->hasOpportunity],
        );
    }

    /**
     * @return array<string, MatchSignal>
     */
    private function signals(ProspectIdentity $identity, CrmOrganizationIdentity $record, DuplicateMatchPolicy $policy): array
    {
        $signals = [];

        $pHost = $identity->normalizedHost();
        $cHost = $record->normalizedHost();
        if ($pHost !== null && $cHost !== null && $pHost === $cHost) {
            $signals[MatchSignal::KEY_DOMAIN_EXACT] = new MatchSignal(
                MatchSignal::KEY_DOMAIN_EXACT, 'strong', 'Normalised domain exact match',
                $pHost, $cHost,
            );

            $pSite = $identity->normalizedWebsite();
            $cSite = $record->normalizedWebsite();
            if ($pSite !== null && $cSite !== null && $pSite === $cSite && str_contains($pSite, '/')) {
                $signals[MatchSignal::KEY_WEBSITE_EXACT] = new MatchSignal(
                    MatchSignal::KEY_WEBSITE_EXACT, 'moderate', 'Full website path also matches',
                    $pSite, $cSite,
                );
            }
        }

        $pName = IdentityNormalizer::nameKey($identity->business);
        $cName = IdentityNormalizer::nameKey($record->name);
        if ($pName !== '' && $cName !== '') {
            if ($pName === $cName) {
                $generic = count($identity->distinctiveTokens()) < $policy->minDistinctiveNameTokens;
                $signals[MatchSignal::KEY_NAME_EXACT] = new MatchSignal(
                    MatchSignal::KEY_NAME_EXACT, $generic ? 'supporting' : 'strong',
                    $generic ? 'Business name match (generic name — weak on its own)' : 'Normalised business-name exact match',
                    $identity->business, $record->name,
                    $generic ? 'Both names reduce to common words; treated as corroboration only.' : null,
                );
            } else {
                $pDist = $identity->distinctiveTokens();
                $cDist = IdentityNormalizer::distinctiveTokens($record->name);
                $dice = IdentityNormalizer::tokenDice($identity->nameTokens(), $record->nameTokens());
                $subset = IdentityNormalizer::tokenSubset($pDist, $cDist);

                $enoughTokens = count($pDist) >= $policy->minDistinctiveNameTokens
                    && count($cDist) >= $policy->minDistinctiveNameTokens;

                if ($enoughTokens && ($dice >= $policy->fuzzyNameDiceThreshold || $subset)) {
                    $signals[MatchSignal::KEY_NAME_FUZZY] = new MatchSignal(
                        MatchSignal::KEY_NAME_FUZZY, 'moderate', 'Business names are a close match',
                        $identity->business, $record->name,
                        $subset ? 'Every distinctive word of the shorter name appears in the other.'
                            : 'Token similarity '.number_format($dice, 2).' ≥ '.number_format($policy->fuzzyNameDiceThreshold, 2).'.',
                    );
                }
            }
        }

        $emailDomain = $record->emailDomain();
        if ($emailDomain !== null && $pHost !== null && $emailDomain === $pHost) {
            $signals[MatchSignal::KEY_EMAIL_DOMAIN] = new MatchSignal(
                MatchSignal::KEY_EMAIL_DOMAIN, 'moderate', 'CRM business email is on the prospect domain',
                $pHost, $emailDomain,
            );
        }

        $shared = array_values(array_intersect(
            array_filter($this->prospectLocationTokens($identity), fn ($t) => mb_strlen($t) >= 4),
            $record->locationTokens(),
        ));
        if ($shared !== []) {
            $signals[MatchSignal::KEY_LOCATION] = new MatchSignal(
                MatchSignal::KEY_LOCATION, 'supporting', 'Location overlaps',
                $identity->location, $this->recordLocation($record),
                'Shared: '.implode(', ', $shared).'. Supporting signal only.',
            );
        }

        return $signals;
    }

    /**
     * @param  array<string, MatchSignal>  $signals
     */
    private function matchStrength(array $signals, bool $bothDistinctive): int
    {
        $points = 0;
        $points += isset($signals[MatchSignal::KEY_DOMAIN_EXACT]) ? 60 : 0;
        $points += isset($signals[MatchSignal::KEY_WEBSITE_EXACT]) ? 10 : 0;
        if (isset($signals[MatchSignal::KEY_NAME_EXACT])) {
            $points += $bothDistinctive ? 30 : 10;
        }
        $points += isset($signals[MatchSignal::KEY_NAME_FUZZY]) ? 18 : 0;
        $points += isset($signals[MatchSignal::KEY_EMAIL_DOMAIN]) ? 12 : 0;
        $points += isset($signals[MatchSignal::KEY_LOCATION]) ? 6 : 0;

        return min(100, $points);
    }

    /**
     * @return list<string>
     */
    private function prospectLocationTokens(ProspectIdentity $identity): array
    {
        if ($identity->location === null) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/[\s,]+/', mb_strtolower($identity->location)) ?: [],
            fn ($t) => mb_strlen($t) >= 3,
        ));
    }

    private function recordLocation(CrmOrganizationIdentity $record): ?string
    {
        $parts = array_filter([$record->city, $record->stateProvince, $record->country]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
