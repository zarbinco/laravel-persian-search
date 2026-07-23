<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Candidates\SearchCandidate;
use Zarbinco\PersianSearch\Candidates\SearchCandidateCollection;
use Zarbinco\PersianSearch\Candidates\SearchCandidateMatch;
use Zarbinco\PersianSearch\Candidates\SearchDocumentField;
use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\ProfessionalSearchRanker;
use Zarbinco\PersianSearch\Ranking\SearchRankingPolicy;
use Zarbinco\PersianSearch\Ranking\SearchRankingPolicyFactory;
use Zarbinco\PersianSearch\Ranking\SearchRankMatcher;
use Zarbinco\PersianSearch\Ranking\SearchRankTier;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Text\UnicodeSearchTokenizer;

final class ProfessionalSearchRankerTest extends TestCase
{
    public function test_professional_search_ranker_evaluates_every_variant_and_preserves_evidence(): void
    {
        $original = $this->variant('orange juice', ['orange', 'juice'], QueryVariantSource::Original, 1000, 'original');
        $synonym = $this->variant('fruit drink', ['fruit', 'drink'], QueryVariantSource::Synonym, 600, 'synonym');
        $record = $this->record(1, [
            'normalized_title' => 'fruit drink',
            'normalized_content' => 'orange juice',
        ]);
        $candidate = $this->candidate($record, [$original, $synonym, $synonym]);
        $collection = (new SearchCandidateCollection(10))->with($candidate);

        $ranked = $this->ranker()->rank($collection);

        $this->assertCount(1, $ranked);
        $this->assertSame(SearchRankTier::ExactTitle, $ranked->all()[0]->rank->tier);
        $this->assertSame(QueryVariantSource::Synonym, $ranked->all()[0]->rank->variant->source);
        $this->assertSame(QueryVariantSource::Original, $ranked->all()[0]->candidate->retrievalVariant->source);
        $this->assertCount(2, $ranked->all()[0]->candidate->matches);
    }

    public function test_equal_semantic_tier_uses_variant_priority_then_fingerprint(): void
    {
        $record = $this->record(1, ['normalized_title' => 'orange']);
        $synonym = $this->variant('orange', ['orange'], QueryVariantSource::Synonym, 600, 'z');
        $original = $this->variant('orange', ['orange'], QueryVariantSource::Original, 1000, 'original');
        $rank = $this->ranker()->rank(
            (new SearchCandidateCollection(10))->with($this->candidate($record, [$synonym, $original])),
        )->all()[0]->rank;

        $this->assertSame(QueryVariantSource::Original, $rank->variant->source);

        $first = $this->variant('orange', ['orange'], QueryVariantSource::Synonym, 600, 'a');
        $second = $this->variant('orange', ['orange'], QueryVariantSource::KeyboardSynonym, 600, 'b');
        $fingerprintRank = $this->ranker()->rank(
            (new SearchCandidateCollection(10))->with($this->candidate($record, [$second, $first])),
        )->all()[0]->rank;

        $this->assertSame('a', $fingerprintRank->variant->fingerprint);
    }

    public function test_coverage_and_matched_count_break_equal_tier_ties_before_document_priority(): void
    {
        $query = $this->variant('orange fruit juice', ['orange', 'fruit', 'juice'], QueryVariantSource::Original, 1000, 'original');
        $one = $this->candidate($this->record(1, ['normalized_title' => 'orange', 'priority' => 100]), [$query]);
        $two = $this->candidate($this->record(2, ['normalized_title' => 'orange fruit', 'priority' => -5]), [$query]);
        $collection = (new SearchCandidateCollection(10))->with($one)->with($two);
        $ranked = $this->ranker()->rank($collection)->all();

        $this->assertSame('2', $ranked[0]->candidate->identity());
        $this->assertSame(6666, $ranked[0]->rank->coverageBasisPoints);
        $this->assertSame(2, $ranked[0]->rank->matchedTokenCount);
        $this->assertSame(-5, $ranked[0]->candidate->document->priority);
    }

    public function test_document_priority_is_late_and_cannot_override_semantic_relevance(): void
    {
        $query = $this->variant('orange', ['orange'], QueryVariantSource::Original, 1000, 'original');
        $content = $this->candidate($this->record(1, [
            'normalized_title' => 'long unrelated title',
            'normalized_content' => 'orange',
            'priority' => 1000,
        ]), [$query]);
        $exact = $this->candidate($this->record(2, [
            'normalized_title' => 'orange',
            'priority' => -100,
        ]), [$query]);
        $ranked = $this->ranker()->rank(
            (new SearchCandidateCollection(10))->with($content)->with($exact),
        )->all();

        $this->assertSame(SearchRankTier::ExactTitle, $ranked[0]->rank->tier);
        $this->assertSame(-100, $ranked[0]->candidate->document->priority);
    }

    public function test_stable_tie_break_order_uses_priority_title_length_source_partition_locale_and_id(): void
    {
        $variant = $this->variant('orange', ['orange'], QueryVariantSource::Original, 1000, 'original');
        $records = [
            $this->record(8, ['source_key' => 'b', 'partition' => 'a', 'locale' => 'en', 'normalized_content' => 'orange']),
            $this->record(7, ['source_key' => 'a', 'partition' => 'b', 'locale' => 'en', 'normalized_content' => 'orange']),
            $this->record(6, ['source_key' => 'a', 'partition' => 'a', 'locale' => 'fa', 'normalized_content' => 'orange']),
            $this->record(5, ['source_key' => 'a', 'partition' => 'a', 'locale' => 'en', 'normalized_content' => 'orange']),
            $this->record(4, ['source_key' => 'a', 'partition' => 'a', 'locale' => 'en', 'normalized_content' => 'orange']),
            $this->record(3, ['source_key' => 'z', 'normalized_title' => 'کیک', 'normalized_content' => 'orange']),
            $this->record(2, ['source_key' => 'z', 'normalized_title' => 'long title', 'normalized_content' => 'orange', 'priority' => 1]),
            $this->record(1, ['source_key' => 'z', 'normalized_title' => 'long title', 'normalized_content' => 'orange', 'priority' => 2]),
        ];
        $collection = new SearchCandidateCollection(20);

        foreach ($records as $record) {
            $collection = $collection->with($this->candidate($record, [$variant]));
        }

        $first = $this->ranker()->rank($collection);
        $second = $this->ranker()->rank($collection);
        $identities = array_map(static fn ($item): string => $item->candidate->identity(), $first->all());

        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8'], $identities);
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame(3, $first->all()[2]->normalizedTitleLength);
    }

    public function test_ranking_tokenizes_each_non_empty_field_once_and_skips_unrankable_candidates(): void
    {
        $tokenizer = new CountingSearchTokenizer;
        $matcher = new SearchRankMatcher($tokenizer, new SearchRankingPolicy(SearchRankingPolicyFactory::defaults()));
        $ranker = new ProfessionalSearchRanker($matcher);
        $variant = $this->variant('ran', ['ran'], QueryVariantSource::Original, 1000, 'original');
        $record = $this->record(1, [
            'normalized_title' => 'orange',
            'normalized_keywords' => 'orange',
            'normalized_excerpt' => 'orange',
            'normalized_content' => 'orange',
        ]);

        $ranked = $ranker->rank(
            (new SearchCandidateCollection(10))->with($this->candidate($record, [$variant])),
        );

        $this->assertCount(0, $ranked);
        $this->assertSame(4, $tokenizer->calls);
    }

    private function ranker(): ProfessionalSearchRanker
    {
        return new ProfessionalSearchRanker(new SearchRankMatcher(
            new UnicodeSearchTokenizer,
            new SearchRankingPolicy(SearchRankingPolicyFactory::defaults()),
        ));
    }

    /** @param array<string, mixed> $attributes */
    private function record(int $id, array $attributes): SearchDocumentRecord
    {
        $record = new SearchDocumentRecord;
        $record->setRawAttributes([
            'id' => $id,
            'source_key' => "page:{$id}",
            'partition' => 'public',
            'locale' => 'en',
            'priority' => 0,
            'normalized_title' => null,
            'normalized_keywords' => null,
            'normalized_excerpt' => null,
            'normalized_content' => null,
            ...$attributes,
        ], true);

        return $record;
    }

    /** @param list<QueryVariant> $variants */
    private function candidate(SearchDocumentRecord $record, array $variants): SearchCandidate
    {
        $matches = array_map(
            static fn (QueryVariant $variant): SearchCandidateMatch => new SearchCandidateMatch(
                $variant,
                [SearchDocumentField::Content],
                [$variant->query],
            ),
            $variants,
        );

        return new SearchCandidate($record, $variants[0], $matches);
    }

    /** @param list<string> $tokens */
    private function variant(
        string $query,
        array $tokens,
        QueryVariantSource $source,
        int $priority,
        string $fingerprint,
    ): QueryVariant {
        return new QueryVariant($query, 'en', $tokens, $source, $priority, $fingerprint);
    }
}

final class CountingSearchTokenizer implements SearchTokenizer
{
    public int $calls = 0;

    public function tokenize(string $normalizedText, string $locale): array
    {
        $this->calls++;

        return explode(' ', $normalizedText);
    }
}
