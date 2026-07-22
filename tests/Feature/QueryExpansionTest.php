<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Generator;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SynonymExpander;
use Zarbinco\PersianSearch\Exceptions\InvalidQueryVariantConfigurationException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Query\DefaultQueryExpander;
use Zarbinco\PersianSearch\Query\WindowsPersianKeyboardMap;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Tests\TestCase;

final class QueryExpansionTest extends TestCase
{
    public function test_contract_resolves_and_original_variant_is_first(): void
    {
        $this->assertInstanceOf(DefaultQueryExpander::class, app(QueryExpander::class));
        $processed = PersianSearch::processQuery('  كیكِ شکلاتي  ', 'fa');
        $variants = PersianSearch::expandQuery($processed);

        $this->assertInstanceOf(QueryVariantCollection::class, $variants);
        $this->assertSame(QueryVariantSource::Original, $variants->all()[0]->source);
        $this->assertSame($processed->normalizedQuery, $variants->all()[0]->query);
        $this->assertSame($processed->searchableTokens, $variants->all()[0]->tokens);
        $this->assertNull($variants->all()[0]->parentFingerprint);
    }

    public function test_keyboard_correction_is_typed_locale_aware_and_deterministic(): void
    {
        $first = $this->expand('\\vjrhg', 'en');
        $second = $this->expand('\\vjrhg', 'en');
        $keyboard = $first->all()[1];

        $this->assertSame(QueryVariantSource::Keyboard, $keyboard->source);
        $this->assertSame('پرتقال', $keyboard->query);
        $this->assertSame(['پرتقال'], $keyboard->tokens);
        $this->assertSame('fa', $keyboard->locale);
        $this->assertSame('en', $keyboard->keyboardCorrection?->sourceLocale);
        $this->assertSame('fa', $keyboard->keyboardCorrection->targetLocale);
        $this->assertSame('en_to_fa', $keyboard->keyboardCorrection->direction->value);
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertGreaterThan($keyboard->priority, $first->all()[0]->priority);
    }

    public function test_standard_keyboard_words_work_and_unmapped_characters_are_preserved(): void
    {
        $this->assertSame('کیف', $this->expand(';dt', 'en')->all()[1]->query);
        $this->assertSame('سامسونگ', $this->expand("shls,k'", 'en')->all()[1]->query);
        $this->assertSame('کیف 123', $this->expand(';dt 123 @', 'en')->all()[1]->query);
    }

    public function test_shift_state_keyboard_keys_remain_distinct_from_base_keys(): void
    {
        $map = app(WindowsPersianKeyboardMap::class)->map();

        $this->assertSame('ز', $map['c']);
        $this->assertSame('ژ', $map['C']);
        $this->assertSame('ا', $map['h']);
        $this->assertSame('آ', $map['H']);
        $this->assertSame('ئ', $map['m']);
        $this->assertSame('ء', $map['M']);
    }

    public function test_keyboard_is_not_generated_for_persian_wrong_locale_or_unchanged_input(): void
    {
        $this->assertCount(1, $this->expand('پرتقال', 'fa'));
        $this->assertCount(1, $this->expand('123 @', 'en'));
    }

    public function test_disabled_keyboard_still_keeps_original(): void
    {
        config()->set('persian-search.keyboard.enabled', false);
        $this->assertCount(1, $this->expand(';dt', 'en'));
    }

    public function test_non_ready_query_produces_empty_collection(): void
    {
        $this->assertTrue($this->expand('!', 'fa')->isEmpty());
    }

    public function test_synonyms_are_token_aware_one_way_and_locale_specific(): void
    {
        $this->configureSynonyms([
            'fa' => ['گل' => ['شکوفه'], 'آبمیوه' => ['نوشیدنی میوه']],
            'en' => ['juice' => ['fruit drink']],
        ]);

        $flower = $this->expand('گل تازه', 'fa');
        $this->assertSame('شکوفه تازه', $flower->all()[1]->query);
        $this->assertSame(0, $flower->all()[1]->appliedSynonyms[0]->tokenStart);
        $this->assertCount(1, $this->expand('گلدان تازه', 'fa'));
        $this->assertCount(1, $this->expand('شکوفه تازه', 'fa'));
        $this->assertCount(1, $this->expand('juice', 'fa'));
        $this->assertSame('fruit drink', $this->expand('juice', 'en')->all()[2]->query);
        $this->assertSame('نوشیدنی میوه تازه', $this->expand('آبمیوه تازه', 'fa')->all()[1]->query);
    }

    public function test_synonym_expansion_is_lazy(): void
    {
        $this->configureSynonyms(['fa' => ['کالا' => ['محصول']]]);
        $original = $this->expand('کالا', 'fa')->original();
        $this->assertNotNull($original);

        $this->assertInstanceOf(Generator::class, app(SynonymExpander::class)->expand($original));
    }

    public function test_synonym_order_position_and_duplicates_are_deterministic(): void
    {
        $this->configureSynonyms(['fa' => ['کالا' => ['محصول', 'محصول', 'جنس']]]);
        $variants = $this->expand('کالا تازه', 'fa')->all();

        $this->assertSame(
            ['کالا تازه', 'محصول تازه', 'جنس تازه'],
            array_map(static fn ($variant): string => $variant->query, $variants),
        );
    }

    public function test_semantic_duplicate_synonyms_do_not_consume_slots_or_hide_later_unique_variants(): void
    {
        config()->set('persian-search.variants.maximum_variants', 5);
        $this->configureSynonyms([
            'en' => [
                'a' => ['x'],
                'a b' => ['x b'],
                'a b c' => ['x b c'],
                'b' => ['y'],
            ],
            'fa' => ['ش ذ ز' => ['فارسی']],
        ]);
        $first = $this->expand('a b c', 'en');
        $second = $this->expand('a b c', 'en');

        $this->assertSame([
            QueryVariantSource::Original,
            QueryVariantSource::Keyboard,
            QueryVariantSource::Synonym,
            QueryVariantSource::Synonym,
            QueryVariantSource::KeyboardSynonym,
        ], array_map(static fn ($variant) => $variant->source, $first->all()));
        $this->assertSame(['a b c', 'ش ذ ز', 'x b c', 'a y c', 'فارسی'], array_map(static fn ($variant): string => $variant->query, $first->all()));
        $this->assertSame($first->toArray(), $second->toArray());
    }

    public function test_keyboard_synonyms_have_complete_provenance(): void
    {
        $this->configureSynonyms(['fa' => ['پرتقال' => ['نارنج']]]);
        $variants = $this->expand('\\vjrhg', 'en')->all();
        $combined = $variants[2];

        $this->assertSame(QueryVariantSource::KeyboardSynonym, $combined->source);
        $this->assertSame('نارنج', $combined->query);
        $this->assertSame('fa', $combined->locale);
        $this->assertNotNull($combined->keyboardCorrection);
        $this->assertCount(1, $combined->appliedSynonyms);
        $this->assertSame($variants[1]->fingerprint, $combined->parentFingerprint);
    }

    public function test_variant_limit_keeps_earlier_higher_priority_variants(): void
    {
        config()->set('persian-search.variants.maximum_variants', 2);
        $this->configureSynonyms(['fa' => ['پرتقال' => ['نارنج', 'مرکبات']]]);
        $variants = $this->expand('\\vjrhg', 'en');

        $this->assertCount(2, $variants);
        $this->assertSame([QueryVariantSource::Original, QueryVariantSource::Keyboard], array_map(static fn ($variant) => $variant->source, $variants->all()));
    }

    public function test_without_expansion_still_returns_original_variant(): void
    {
        $variants = PersianSearch::query(';dt')->locale('en')->withoutExpansion()->variants();

        $this->assertCount(1, $variants);
        $this->assertSame(QueryVariantSource::Original, $variants->original()?->source);
    }

    public function test_invalid_variant_priority_configuration_is_rejected(): void
    {
        config()->set('persian-search.variants.priorities.keyboard', 1000);
        $this->expectException(InvalidQueryVariantConfigurationException::class);

        $this->expand('orange', 'en');
    }

    public function test_invalid_variant_limit_configuration_is_rejected(): void
    {
        config()->set('persian-search.variants.maximum_variants', 0);
        $this->expectException(InvalidQueryVariantConfigurationException::class);

        $this->expand('orange', 'en');
    }

    public function test_keyboard_direction_and_specific_source_locale_configuration_are_active(): void
    {
        config()->set('persian-search.keyboard.en_to_fa.source_locale', 'en-US');

        $this->assertCount(1, $this->expand(';dt', 'en'));
        $this->assertSame('کیف', $this->expand(';dt', 'en-US')->all()[1]->query);
    }

    public function test_malformed_synonym_dictionary_is_rejected(): void
    {
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.synonyms.locales', ['fa' => ['' => ['جایگزین']]]);
        $this->expectException(InvalidQueryVariantConfigurationException::class);

        $this->expand('کالا', 'fa');
    }

    public function test_self_synonym_replacement_is_rejected_after_normalization(): void
    {
        $this->configureSynonyms(['fa' => ['كيك' => ['کیک']]]);
        $this->expectException(InvalidQueryVariantConfigurationException::class);

        $this->expand('کیک', 'fa');
    }

    public function test_non_string_and_associative_synonym_replacements_are_rejected(): void
    {
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.synonyms.locales', ['fa' => ['کالا' => ['named' => 'محصول']]]);
        $this->expectException(InvalidQueryVariantConfigurationException::class);

        $this->expand('کالا', 'fa');
    }

    /** @param array<string, array<string, list<string>>> $locales */
    private function configureSynonyms(array $locales): void
    {
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.synonyms.locales', $locales);
    }

    private function expand(string $query, string $locale): QueryVariantCollection
    {
        return PersianSearch::expandQuery(PersianSearch::processQuery($query, $locale));
    }
}
