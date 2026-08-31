<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.4 (spec §17): the MINIMAL slice of an authorised CRM organisation
 * the pure duplicate matcher is allowed to see — identity fields only.
 * No notes, no communications, no opportunity value, no economics, no
 * owner/team, no history.
 *
 * `ProspectDuplicateCheckService` builds these ONLY from CRM rows the
 * actor is already authorised for (server-side `scopeToUser`), so the
 * pure matcher never has any way to reach a restricted record.
 */
final readonly class CrmOrganizationIdentity
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $website,
        public ?string $email,
        public ?string $city,
        public ?string $stateProvince,
        public ?string $country,
        public bool $hasLead = false,
        public bool $hasOpportunity = false,
    ) {}

    public function normalizedHost(): ?string
    {
        return IdentityNormalizer::host($this->website);
    }

    public function normalizedWebsite(): ?string
    {
        return IdentityNormalizer::website($this->website);
    }

    public function emailDomain(): ?string
    {
        if ($this->email === null || ! str_contains($this->email, '@')) {
            return null;
        }

        return IdentityNormalizer::host(mb_strtolower(trim(explode('@', $this->email, 2)[1])));
    }

    /** @return list<string> */
    public function nameTokens(): array
    {
        return IdentityNormalizer::nameTokens($this->name);
    }

    /** @return list<string> */
    public function locationTokens(): array
    {
        $parts = array_filter([$this->city, $this->stateProvince, $this->country]);

        $tokens = [];
        foreach ($parts as $part) {
            foreach (preg_split('/[\s,]+/', mb_strtolower($part)) ?: [] as $token) {
                if (mb_strlen($token) >= 3) {
                    $tokens[] = $token;
                }
            }
        }

        return array_values(array_unique($tokens));
    }
}
