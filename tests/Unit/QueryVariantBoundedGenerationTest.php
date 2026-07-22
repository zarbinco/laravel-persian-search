<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use RuntimeException;
use Zarbinco\PersianSearch\Contracts\SynonymExpander;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Query\DefaultQueryExpander;
use Zarbinco\PersianSearch\Query\KeyboardLayoutCorrector;
use Zarbinco\PersianSearch\Query\QueryVariantPolicy;
use Zarbinco\PersianSearch\Query\SynonymExpansion;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Tests\TestCase;

final class QueryVariantBoundedGenerationTest extends TestCase
{
    public function test_variant_limit_one_never_invokes_synonym_expansion(): void
    {
        $synonyms = new GuardedSynonymExpander(0);
        $variants = $this->expander(1, $synonyms)->expand(PersianSearch::processQuery('کالا', 'fa'));

        $this->assertCount(1, $variants);
        $this->assertSame(0, $synonyms->calls);
        $this->assertSame(0, $synonyms->consumed);
    }

    public function test_original_and_keyboard_filling_limit_never_invoke_synonyms(): void
    {
        $synonyms = new GuardedSynonymExpander(0);
        $variants = $this->expander(2, $synonyms)->expand(PersianSearch::processQuery('\\vjrhg', 'en'));

        $this->assertSame([QueryVariantSource::Original, QueryVariantSource::Keyboard], $this->sources($variants));
        $this->assertSame(0, $synonyms->calls);
        $this->assertSame(0, $synonyms->consumed);
    }

    public function test_exactly_one_remaining_slot_consumes_only_one_generator_item(): void
    {
        $synonyms = new GuardedSynonymExpander(1, 1000);
        $variants = $this->expander(2, $synonyms)->expand(PersianSearch::processQuery('کالا', 'fa'));

        $this->assertCount(2, $variants);
        $this->assertSame(1, $synonyms->calls);
        $this->assertSame(1, $synonyms->consumed);
        $this->assertSame([QueryVariantSource::Original, QueryVariantSource::Synonym], $this->sources($variants));
    }

    public function test_group_order_and_repeated_expansion_remain_deterministic(): void
    {
        $synonyms = new GuardedSynonymExpander(4, 1);
        $expander = $this->expander(4, $synonyms);
        $processed = PersianSearch::processQuery('\\vjrhg', 'en');
        $first = $expander->expand($processed);
        $second = $expander->expand($processed);

        $this->assertSame([
            QueryVariantSource::Original,
            QueryVariantSource::Keyboard,
            QueryVariantSource::Synonym,
            QueryVariantSource::KeyboardSynonym,
        ], $this->sources($first));
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame(4, $synonyms->calls);
        $this->assertSame(4, $synonyms->consumed);
    }

    private function expander(int $maximum, SynonymExpander $synonyms): DefaultQueryExpander
    {
        return new DefaultQueryExpander(
            QueryVariantPolicy::fromArray($maximum, [
                'original' => 1000,
                'keyboard' => 800,
                'synonym' => 600,
                'keyboard_synonym' => 400,
            ]),
            app(KeyboardLayoutCorrector::class),
            $synonyms,
        );
    }

    /** @return list<QueryVariantSource> */
    private function sources(QueryVariantCollection $variants): array
    {
        return array_map(static fn (QueryVariant $variant): QueryVariantSource => $variant->source, $variants->all());
    }
}

final class GuardedSynonymExpander implements SynonymExpander
{
    public int $calls = 0;

    public int $consumed = 0;

    public function __construct(
        private readonly int $maximumConsumption,
        private readonly int $available = 10,
    ) {}

    public function expand(QueryVariant $variant): iterable
    {
        $this->calls++;

        for ($index = 1; $index <= $this->available; $index++) {
            $this->consumed++;

            if ($this->consumed > $this->maximumConsumption) {
                throw new RuntimeException('Synonym generator was advanced beyond the available variant capacity.');
            }

            $suffix = $variant->locale === 'fa' ? 'جایگزین'.$index : 'alternative'.$index;
            $query = $variant->query.' '.$suffix;
            $tokens = [...$variant->tokens, $suffix];

            yield new SynonymExpansion(
                sourceTerm: $variant->tokens[0],
                replacementTerm: $suffix,
                query: $query,
                tokens: $tokens,
                locale: $variant->locale,
                tokenStart: 0,
                tokenLength: 1,
                fingerprint: hash('sha256', $variant->fingerprint."\0".$suffix),
            );
        }
    }
}
