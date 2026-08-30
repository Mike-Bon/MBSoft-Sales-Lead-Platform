<?php

namespace App\Support\MarketIntelligence;

use Illuminate\Validation\ValidationException;

/**
 * V2.1: a natural-language discovery request, converted by the LLM into
 * these narrow fields and then VALIDATED and NORMALISED here before any
 * external call is made (spec §3 — "converted into a narrow validated
 * discovery request", "set sensible limits on ... query length").
 *
 * This is a value object with no behaviour beyond its own validation.
 * It never touches the network, the CRM, or configuration for anything
 * other than the result cap.
 */
final readonly class DiscoveryCriteria
{
    private const MAX_FIELD_CHARS = 120;

    private const MAX_KEYWORD_CHARS = 40;

    private const MAX_KEYWORDS = 10;

    private const MAX_TOTAL_CHARS = 600;

    public const ALLOWED_ONLINE_SIGNALS = ['own_website', 'facebook', 'instagram', 'tiktok', 'marketplace'];

    /**
     * @param  list<string>  $productKeywords
     * @param  list<string>  $onlineSignals
     * @param  list<string>  $excludeKeywords
     */
    public function __construct(
        public ?string $location,
        public ?string $industry,
        public array $productKeywords,
        public array $onlineSignals,
        public array $excludeKeywords,
        public int $maxResults,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromArray(array $input, int $resultCap): self
    {
        $location = self::cleanField($input['location'] ?? null);
        $industry = self::cleanField($input['industry'] ?? null);
        $products = self::cleanKeywords($input['product_keywords'] ?? []);
        $signals = array_values(array_intersect(
            self::cleanKeywords($input['online_signals'] ?? [], strtolower: true),
            self::ALLOWED_ONLINE_SIGNALS,
        ));
        $exclude = self::cleanKeywords($input['exclude_keywords'] ?? []);

        if ($location === null && $industry === null && $products === []) {
            throw ValidationException::withMessages([
                'criteria' => 'Provide at least a location, an industry, or one product keyword to search for.',
            ]);
        }

        $total = strlen((string) $location) + strlen((string) $industry)
            + strlen(implode(' ', $products)) + strlen(implode(' ', $exclude));

        if ($total > self::MAX_TOTAL_CHARS) {
            throw ValidationException::withMessages([
                'criteria' => 'The discovery criteria are too long. Narrow the request.',
            ]);
        }

        $requested = (int) ($input['max_results'] ?? 10);
        $maxResults = max(1, min($requested > 0 ? $requested : 10, $resultCap));

        return new self($location, $industry, $products, $signals, $exclude, $maxResults);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'location' => $this->location,
            'industry' => $this->industry,
            'product_keywords' => $this->productKeywords,
            'online_signals' => $this->onlineSignals,
            'exclude_keywords' => $this->excludeKeywords,
            'max_results' => $this->maxResults,
        ];
    }

    private static function cleanField(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        $value = mb_substr($value, 0, self::MAX_FIELD_CHARS);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function cleanKeywords(mixed $value, bool $strtolower = false): array
    {
        $items = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);

        $clean = [];

        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim(preg_replace('/\s+/', ' ', $item) ?? '');
            $item = mb_substr($item, 0, self::MAX_KEYWORD_CHARS);

            if ($strtolower) {
                $item = strtolower($item);
            }

            if ($item !== '') {
                $clean[] = $item;
            }
        }

        return array_values(array_slice(array_unique($clean), 0, self::MAX_KEYWORDS));
    }
}
