<?php

namespace Tests\Feature\MarketIntelligence;

use App\Support\MarketIntelligence\IdentityNormalizer;
use Tests\TestCase;

/**
 * V2.4 (spec §11/§34): deterministic, conservative identity
 * normalization — must never fold two genuinely different businesses
 * into one.
 */
class IdentityNormalizerTest extends TestCase
{
    /**
     * @dataProvider hosts
     */
    public function test_host_normalization(string $input, ?string $expected): void
    {
        $this->assertSame($expected, IdentityNormalizer::host($input));
    }

    /**
     * @return list<array{0: string, 1: ?string}>
     */
    public static function hosts(): array
    {
        return [
            ['https://www.abcbeauty.ph/products?x=1', 'abcbeauty.ph'],
            ['http://ABCBeauty.PH', 'abcbeauty.ph'],
            ['abcbeauty.ph', 'abcbeauty.ph'],
            ['www.abcbeauty.ph/', 'abcbeauty.ph'],
            ['  https://abcbeauty.ph  ', 'abcbeauty.ph'],
            ['shop.abcbeauty.ph', 'shop.abcbeauty.ph'],
            ['not a url', null],
            ['', null],
            ['localhost', null],
        ];
    }

    public function test_www_and_non_www_normalize_identically(): void
    {
        $this->assertSame(
            IdentityNormalizer::host('https://www.abcbeauty.ph/'),
            IdentityNormalizer::host('abcbeauty.ph'),
        );
    }

    public function test_website_keeps_path_but_drops_scheme_query_and_trailing_slash(): void
    {
        $this->assertSame('abcbeauty.ph/shop', IdentityNormalizer::website('https://www.abcbeauty.ph/shop/?ref=x'));
        $this->assertSame('abcbeauty.ph', IdentityNormalizer::website('https://abcbeauty.ph/'));
    }

    /**
     * @dataProvider names
     */
    public function test_name_key_normalization(string $input, string $expected): void
    {
        $this->assertSame($expected, IdentityNormalizer::nameKey($input));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function names(): array
    {
        return [
            ['ABC Beauty Corporation', 'abc beauty'],
            ['ABC Beauty Corp.', 'abc beauty'],
            ['  ABC   Beauty,  Inc. ', 'abc beauty'],
            ['ABC Beauty Co., Ltd.', 'abc beauty'],
            ['ABC-Beauty (Philippines)', 'abc beauty philippines'],
            ['ABC Beauty', 'abc beauty'],
        ];
    }

    public function test_legal_suffix_normalization_does_not_merge_distinct_companies(): void
    {
        $this->assertNotSame(
            IdentityNormalizer::nameKey('ABC Trading'),
            IdentityNormalizer::nameKey('ABC Trading Solutions'),
        );
        $this->assertSame('abc trading', IdentityNormalizer::nameKey('ABC Trading Inc.'));
        $this->assertSame('abc trading solutions', IdentityNormalizer::nameKey('ABC Trading Solutions'));
    }

    public function test_distinctive_tokens_strip_generic_words(): void
    {
        $this->assertSame(['abc', 'beauty'], IdentityNormalizer::distinctiveTokens('ABC Beauty Shop Cebu'));
        $this->assertSame([], IdentityNormalizer::distinctiveTokens('Online Store Philippines'));
        $this->assertSame(['glow'], IdentityNormalizer::distinctiveTokens('Glow Trading'));
    }

    public function test_token_dice_and_subset(): void
    {
        $this->assertSame(1.0, IdentityNormalizer::tokenDice(['abc', 'beauty'], ['abc', 'beauty']));
        $this->assertGreaterThan(0.6, IdentityNormalizer::tokenDice(['abc', 'beauty', 'store'], ['abc', 'beauty']));
        $this->assertTrue(IdentityNormalizer::tokenSubset(['abc', 'beauty'], ['abc', 'beauty', 'philippines']));
        $this->assertFalse(IdentityNormalizer::tokenSubset(['abc', 'trading'], ['abc', 'beauty']));
    }
}
