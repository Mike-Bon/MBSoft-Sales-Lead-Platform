<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.4 (spec §16): one transparent reason a prospect matched (or nearly
 * matched) a CRM record. Always carries both compared values so a human
 * can verify the match themselves.
 */
final readonly class MatchSignal
{
    public const KEY_DOMAIN_EXACT = 'domain_exact';

    public const KEY_WEBSITE_EXACT = 'website_exact';

    public const KEY_NAME_EXACT = 'name_exact';

    public const KEY_NAME_FUZZY = 'name_fuzzy';

    public const KEY_EMAIL_DOMAIN = 'email_domain';

    public const KEY_LOCATION = 'location';

    /** strong | moderate | supporting */
    public function __construct(
        public string $key,
        public string $strength,
        public string $label,
        public ?string $prospectValue,
        public ?string $crmValue,
        public ?string $detail = null,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'signal' => $this->key,
            'strength' => $this->strength,
            'label' => $this->label,
            'prospect_value' => $this->prospectValue,
            'crm_value' => $this->crmValue,
            'detail' => $this->detail,
        ];
    }
}
