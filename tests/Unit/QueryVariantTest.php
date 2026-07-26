<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;
use Zarbinco\PersianSearch\Search\QueryVariantSource;

final class QueryVariantTest extends TestCase
{
    public function test_collection_deduplicates_query_locale_by_priority_and_keeps_locale_distinct(): void
    {
        $collection = new QueryVariantCollection(3);
        $low = $this->variant('orange', 'en', QueryVariantSource::Synonym, 600, 'low');
        $high = $this->variant('orange', 'en', QueryVariantSource::Keyboard, 800, 'high');
        $persian = $this->variant('orange', 'fa', QueryVariantSource::Synonym, 600, 'persian');

        $collection = $collection->with($low)->with($high)->with($persian);
        $this->assertCount(2, $collection);
        $this->assertSame($high, $collection->all()[0]);
        $this->assertSame($persian, $collection->all()[1]);
    }

    public function test_equal_priority_keeps_first_and_fingerprint_duplicates_are_ignored(): void
    {
        $collection = new QueryVariantCollection(2);
        $first = $this->variant('one', 'en', QueryVariantSource::Synonym, 600, 'same');

        $collection = $collection->with($first);
        $unchanged = $collection->with($first)->with($this->variant('one', 'en', QueryVariantSource::Synonym, 600, 'other'));
        $this->assertSame($collection->toArray(), $unchanged->toArray());
        $this->assertSame($first, $collection->all()[0]);
    }

    public function test_existing_positional_applied_synonyms_argument_remains_backward_compatible(): void
    {
        $variant = new QueryVariant(
            'orange',
            'en',
            ['orange'],
            QueryVariantSource::Original,
            1000,
            'fingerprint',
            null,
            null,
            [],
        );

        $this->assertSame([], $variant->appliedSynonyms);
        $this->assertNull($variant->spellingCorrection);
        $this->assertNull($variant->advancedCorrection);
    }

    public function test_collection_rejects_non_positive_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QueryVariantCollection(0);
    }

    public function test_contextual_priority_replacement_preserves_lineage_and_exact_capacity(): void
    {
        $original = $this->variant('oragne form', 'en', QueryVariantSource::Original, 1000, 'original');
        $keyboard = $this->variant('خقشىلث بخقپ', 'fa', QueryVariantSource::Keyboard, 800, 'keyboard', 'original');
        $spelling = $this->variant('orange form', 'en', QueryVariantSource::Spelling, 700, 'spelling', 'original');
        $synonym = $this->variant('citrus form', 'en', QueryVariantSource::Synonym, 600, 'synonym', 'original');
        $keyboardSynonym = $this->variant(
            'کیبورد مترادف',
            'fa',
            QueryVariantSource::KeyboardSynonym,
            400,
            'keyboard-synonym',
            'keyboard',
        );
        $collection = new QueryVariantCollection(5, [
            $original,
            $keyboard,
            $spelling,
            $synonym,
            $keyboardSynonym,
        ]);
        $contextual = $this->variant(
            'orange from',
            'en',
            QueryVariantSource::Contextual,
            500,
            'contextual',
            'spelling',
        );

        $replaced = $collection->withPriorityReplacement($contextual);

        $this->assertCount(5, $replaced);
        $this->assertTrue($replaced->contains('original'));
        $this->assertTrue($replaced->contains('spelling'));
        $this->assertTrue($replaced->contains('contextual'));
        $this->assertFalse($replaced->contains('keyboard-synonym'));
        $this->assertSame($replaced->toArray(), $collection->withPriorityReplacement($contextual)->toArray());
    }

    public function test_contextual_priority_replacement_never_removes_original_or_required_lineage(): void
    {
        $original = $this->variant('original', 'en', QueryVariantSource::Original, 1000, 'original');
        $contextual = $this->variant(
            'corrected',
            'en',
            QueryVariantSource::Contextual,
            500,
            'contextual',
            'original',
        );

        $maximumOne = (new QueryVariantCollection(1, [$original]))
            ->withPriorityReplacement($contextual);
        $this->assertSame([$original], $maximumOne->all());

        $spelling = $this->variant('spelling', 'en', QueryVariantSource::Spelling, 700, 'spelling', 'original');
        $synonym = $this->variant('synonym', 'en', QueryVariantSource::Synonym, 600, 'synonym', 'spelling');
        $fullLineage = new QueryVariantCollection(3, [$original, $spelling, $synonym]);
        $notInserted = $fullLineage->withPriorityReplacement(
            $this->variant('corrected', 'en', QueryVariantSource::Contextual, 500, 'contextual', 'spelling'),
        );

        $this->assertSame($fullLineage->toArray(), $notInserted->toArray());
        $this->assertFalse($notInserted->contains('contextual'));
    }

    public function test_priority_replacement_does_not_remove_an_unrelated_variant_for_a_semantic_duplicate(): void
    {
        $original = $this->variant('original', 'en', QueryVariantSource::Original, 1000, 'original');
        $semanticWinner = $this->variant('corrected', 'en', QueryVariantSource::Spelling, 700, 'winner', 'original');
        $unrelatedLeaf = $this->variant('unrelated', 'en', QueryVariantSource::Synonym, 400, 'unrelated', 'original');
        $collection = new QueryVariantCollection(3, [$original, $semanticWinner, $unrelatedLeaf]);

        $unchanged = $collection->withPriorityReplacement(
            $this->variant('corrected', 'en', QueryVariantSource::Contextual, 500, 'duplicate', 'original'),
        );

        $this->assertSame($collection->toArray(), $unchanged->toArray());
        $this->assertTrue($unchanged->contains('unrelated'));
        $this->assertFalse($unchanged->contains('duplicate'));
    }

    public function test_priority_replacement_replaces_only_a_lower_priority_semantic_leaf(): void
    {
        $original = $this->variant('original', 'en', QueryVariantSource::Original, 1000, 'original');
        $duplicateLeaf = $this->variant('corrected', 'en', QueryVariantSource::KeyboardSynonym, 400, 'duplicate', 'original');
        $unrelatedLeaf = $this->variant('unrelated', 'en', QueryVariantSource::Synonym, 300, 'unrelated', 'original');
        $collection = new QueryVariantCollection(3, [$original, $unrelatedLeaf, $duplicateLeaf]);
        $contextual = $this->variant(
            'corrected',
            'en',
            QueryVariantSource::Contextual,
            500,
            'contextual',
            'original',
        );

        $replaced = $collection->withPriorityReplacement($contextual);

        $this->assertCount(3, $replaced);
        $this->assertTrue($replaced->contains('contextual'));
        $this->assertTrue($replaced->contains('unrelated'));
        $this->assertFalse($replaced->contains('duplicate'));
        $this->assertSame($replaced->toArray(), $collection->withPriorityReplacement($contextual)->toArray());
    }

    public function test_priority_replacement_preserves_a_semantic_duplicate_that_is_a_parent(): void
    {
        $original = $this->variant('original', 'en', QueryVariantSource::Original, 1000, 'original');
        $duplicateParent = $this->variant('corrected', 'en', QueryVariantSource::KeyboardSynonym, 400, 'duplicate', 'original');
        $child = $this->variant('child', 'en', QueryVariantSource::Synonym, 300, 'child', 'duplicate');
        $collection = new QueryVariantCollection(3, [$original, $duplicateParent, $child]);

        $unchanged = $collection->withPriorityReplacement(
            $this->variant('corrected', 'en', QueryVariantSource::Contextual, 500, 'contextual', 'original'),
        );

        $this->assertSame($collection->toArray(), $unchanged->toArray());
        $this->assertTrue($unchanged->contains('duplicate'));
        $this->assertTrue($unchanged->contains('child'));
    }

    private function variant(
        string $query,
        string $locale,
        QueryVariantSource $source,
        int $priority,
        string $fingerprint,
        ?string $parent = null,
    ): QueryVariant {
        return new QueryVariant($query, $locale, [$query], $source, $priority, $fingerprint, $parent);
    }
}
