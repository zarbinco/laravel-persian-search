<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchDocumentSetException;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Providers\SearchDocumentSet;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

final class SearchDocumentSetTest extends TestCase
{
    public function test_empty_one_and_multiple_document_sets_are_valid_and_ordered(): void
    {
        $reference = new SearchSourceReference('page:about', 'page', null);
        $empty = SearchDocumentSet::fromIterable($reference, [], 'pages');
        $fa = $this->document('public', 'fa');
        $en = $this->document('public', 'en');
        $admin = $this->document('admin', 'fa');
        $set = SearchDocumentSet::fromIterable($reference, [$fa, $en, $admin], 'pages');

        $this->assertTrue($empty->isEmpty());
        $this->assertCount(3, $set);
        $this->assertSame([$fa, $en, $admin], $set->all());
        $this->assertSame($reference, $set->reference);
        $this->assertCount(3, $set->toArray()['documents']);
    }

    public function test_duplicate_identity_is_rejected(): void
    {
        $reference = new SearchSourceReference('page:about', 'page', null);
        $this->expectException(InvalidSearchDocumentSetException::class);

        SearchDocumentSet::fromIterable($reference, [$this->document('public', 'fa'), $this->document('public', 'fa')], 'pages');
    }

    #[DataProvider('mismatches')]
    public function test_source_mismatches_are_rejected(string $key, string $type, int|string|null $id): void
    {
        $reference = new SearchSourceReference('page:about', 'page', null);
        $this->expectException(InvalidSearchDocumentSetException::class);

        SearchDocumentSet::fromIterable($reference, [$this->document('public', 'fa', $key, $type, $id)], 'pages');
    }

    /** @return array<string, array{string, string, int|string|null}> */
    public static function mismatches(): array
    {
        return [
            'key' => ['page:wrong', 'page', null],
            'type' => ['page:about', 'wrong', null],
            'id' => ['page:about', 'page', '1'],
        ];
    }

    public function test_invalid_yielded_value_identifies_provider_without_content(): void
    {
        $this->expectException(InvalidSearchDocumentSetException::class);
        $this->expectExceptionMessage('provider [pages]');

        SearchDocumentSet::fromIterable(new SearchSourceReference('page:about', 'page', null), ['invalid'], 'pages');
    }

    private function document(
        string $partition,
        string $locale,
        string $key = 'page:about',
        string $type = 'page',
        int|string|null $id = null,
    ): SearchDocument {
        return new SearchDocument(
            partition: $partition,
            sourceKey: $key,
            sourceType: $type,
            sourceId: $id,
            locale: $locale,
            title: 'About',
            excerpt: null,
            normalizedTitle: 'about',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: 'about',
        );
    }
}
