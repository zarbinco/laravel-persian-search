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
    }

    public function test_collection_rejects_non_positive_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QueryVariantCollection(0);
    }

    private function variant(string $query, string $locale, QueryVariantSource $source, int $priority, string $fingerprint): QueryVariant
    {
        return new QueryVariant($query, $locale, [$query], $source, $priority, $fingerprint);
    }
}
