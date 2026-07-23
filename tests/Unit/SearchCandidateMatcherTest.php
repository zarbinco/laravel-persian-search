<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Candidates\SearchCandidate;
use Zarbinco\PersianSearch\Candidates\SearchCandidateCollection;
use Zarbinco\PersianSearch\Candidates\SearchCandidateMatcher;
use Zarbinco\PersianSearch\Candidates\SearchCandidatePlan;
use Zarbinco\PersianSearch\Candidates\SearchDocumentField;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantSource;

final class SearchCandidateMatcherTest extends TestCase
{
    public function test_candidate_matcher_records_deterministic_fields_and_terms_without_values(): void
    {
        $record = $this->record(1, [
            'normalized_title' => '100% file_name',
            'normalized_keywords' => 'folder\\file',
            'normalized_excerpt' => null,
            'normalized_content' => '100% content',
        ]);
        $plan = $this->plan($this->variant('100% file_name', QueryVariantSource::Original, 1000), [
            '100% file_name',
            '100%',
            'file_name',
            'folder\\file',
        ]);
        $match = (new SearchCandidateMatcher)->match($record, $plan);

        $this->assertNotNull($match);
        $this->assertSame([
            SearchDocumentField::Title,
            SearchDocumentField::Keywords,
            SearchDocumentField::Content,
        ], $match->fields);
        $this->assertSame(['100% file_name', '100%', 'file_name', 'folder\\file'], $match->terms);
        $this->assertArrayNotHasKey('values', $match->toArray());
        $this->assertStringNotContainsString('100% content', json_encode($match->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_candidate_matcher_rejects_database_collation_false_positive_and_handles_nulls(): void
    {
        $record = $this->record(1, [
            'normalized_title' => 'ORANGE',
            'normalized_keywords' => null,
            'normalized_excerpt' => null,
            'normalized_content' => '',
        ]);

        $this->assertNull((new SearchCandidateMatcher)->match(
            $record,
            $this->plan($this->variant('orange', QueryVariantSource::Original, 1000), ['orange']),
        ));
    }

    public function test_candidate_collection_deduplicates_document_merges_evidence_and_preserves_retrieval_variant(): void
    {
        $record = $this->record(1, ['normalized_title' => 'orange citrus']);
        $synonym = $this->variant('citrus', QueryVariantSource::Synonym, 600);
        $original = $this->variant('orange', QueryVariantSource::Original, 1000);
        $matcher = new SearchCandidateMatcher;
        $synonymMatch = $matcher->match($record, $this->plan($synonym, ['citrus']));
        $originalMatch = $matcher->match($record, $this->plan($original, ['orange']));
        $this->assertNotNull($synonymMatch);
        $this->assertNotNull($originalMatch);

        $collection = new SearchCandidateCollection(1);
        $collection = $collection->with(SearchCandidate::fromMatch($record, $synonymMatch));
        $collection = $collection->with(SearchCandidate::fromMatch($record, $originalMatch));
        $collection = $collection->with(SearchCandidate::fromMatch($record, $originalMatch));
        $collection = $collection->with(SearchCandidate::fromMatch(
            $this->record(2, ['normalized_title' => 'orange']),
            $originalMatch,
        ));

        $this->assertCount(1, $collection);
        $this->assertTrue($collection->isFull());
        $this->assertSame(QueryVariantSource::Original, $collection->all()[0]->retrievalVariant->source);
        $this->assertCount(2, $collection->all()[0]->matches);
        $this->assertSame($collection->toArray(), $collection->toArray());
    }

    /** @param array<string, mixed> $attributes */
    private function record(int $id, array $attributes): SearchDocumentRecord
    {
        $record = new SearchDocumentRecord;
        $record->setRawAttributes(['id' => $id, ...$attributes], true);

        return $record;
    }

    /** @param list<string> $terms */
    private function plan(QueryVariant $variant, array $terms): SearchCandidatePlan
    {
        return new SearchCandidatePlan($variant, $terms, SearchDocumentField::cases(), 'public', [], 100);
    }

    private function variant(string $query, QueryVariantSource $source, int $priority): QueryVariant
    {
        return new QueryVariant($query, 'en', [$query], $source, $priority, $source->value.$query);
    }
}
