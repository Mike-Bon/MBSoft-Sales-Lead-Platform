<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.2 (spec §6/§7): one thing a prospect is evaluated against.
 *
 * A criterion is always either derived from the user's V2.1 discovery
 * request or supplied as an explicit validated qualification criterion —
 * the application never invents a business requirement (spec §6). Each
 * carries whether it is HARD or SUPPORTING (spec §7).
 */
final readonly class QualificationCriterion
{
    public const KEY_LOCATION = 'location';

    public const KEY_INDUSTRY = 'industry';

    public const KEY_PRODUCT = 'product';

    public const KEY_ONLINE_SELLING = 'online_selling';

    public const KEY_OWN_WEBSITE = 'own_website';

    public const KEY_ECOMMERCE = 'ecommerce';

    public const KEY_SOCIAL_PRESENCE = 'social_presence';

    public const KEY_SHIPPING = 'shipping';

    public const KEY_MARKETPLACE = 'marketplace';

    public const KEY_PHYSICAL_PRODUCTS = 'physical_products';

    public const KEY_KEYWORD = 'keyword';

    /** @var list<string> keys the evaluator understands structurally */
    public const KNOWN_KEYS = [
        self::KEY_LOCATION, self::KEY_INDUSTRY, self::KEY_PRODUCT, self::KEY_ONLINE_SELLING,
        self::KEY_OWN_WEBSITE, self::KEY_ECOMMERCE, self::KEY_SOCIAL_PRESENCE, self::KEY_SHIPPING,
        self::KEY_MARKETPLACE, self::KEY_PHYSICAL_PRODUCTS,
    ];

    public function __construct(
        public string $key,
        public CriterionKind $kind,
        public string $label,
        public ?string $expected = null,
    ) {}

    public function isHard(): bool
    {
        return $this->kind === CriterionKind::Hard;
    }

    /** Stable identity for de-duplication (key + expected value). */
    public function signature(): string
    {
        return $this->key.'|'.mb_strtolower($this->expected ?? '');
    }

    public function withKind(CriterionKind $kind): self
    {
        return new self($this->key, $kind, $this->label, $this->expected);
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind->value,
            'label' => $this->label,
            'expected' => $this->expected,
        ];
    }
}
