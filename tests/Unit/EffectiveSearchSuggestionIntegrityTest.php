<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use RuntimeException;
use Zarbinco\PersianSearch\Candidates\SearchCandidate;
use Zarbinco\PersianSearch\Candidates\SearchCandidateMatch;
use Zarbinco\PersianSearch\Candidates\SearchDocumentField;
use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\SearchRank;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidate;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidateCollection;
use Zarbinco\PersianSearch\Ranking\SearchRankingPolicy;
use Zarbinco\PersianSearch\Ranking\SearchRankMatcher;
use Zarbinco\PersianSearch\Ranking\SearchRankTier;
use Zarbinco\PersianSearch\Search\EffectiveSearchSuggestionEvaluator;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Search\SearchSuggestionPolicy;
use Zarbinco\PersianSearch\Tests\TestCase;

final class EffectiveSearchSuggestionIntegrityTest extends TestCase
{
    public function test_lineage_missing_parent_is_detected_without_candidate_results(): void
    {
        $variants = new QueryVariantCollection(2, [
            $this->variant('synonym', QueryVariantSource::Synonym, 'missing'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->evaluator(new RecordingSearchTokenizer)->evaluate(
            new SearchRankedCandidateCollection([]),
            $variants,
            true,
            'original',
        );
    }

    public function test_lineage_parent_cycle_is_detected_without_candidate_results(): void
    {
        $variants = new QueryVariantCollection(2, [
            $this->variant('first', QueryVariantSource::Synonym, 'second'),
            $this->variant('second', QueryVariantSource::Synonym, 'first'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->evaluator(new RecordingSearchTokenizer)->evaluate(
            new SearchRankedCandidateCollection([]),
            $variants,
            true,
            'original',
        );
    }

    public function test_no_keyboard_root_returns_null_without_document_tokenization(): void
    {
        $tokenizer = new RecordingSearchTokenizer;
        $original = $this->variant('original', QueryVariantSource::Original);
        $variants = new QueryVariantCollection(1, [$original]);
        $ranked = new SearchRankedCandidateCollection([$this->ranked($original)]);

        $suggestion = $this->evaluator($tokenizer)->evaluate($ranked, $variants, true, 'original');

        $this->assertNull($suggestion);
        $this->assertSame(0, $tokenizer->calls);
    }

    public function test_contextual_priority_replacement_keeps_a_valid_suggestion_lineage(): void
    {
        $tokenizer = new RecordingSearchTokenizer;
        $original = new QueryVariant(
            'original',
            'en',
            ['original'],
            QueryVariantSource::Original,
            1000,
            'original',
        );
        $low = new QueryVariant(
            'low',
            'en',
            ['low'],
            QueryVariantSource::KeyboardSynonym,
            400,
            'low',
            'original',
        );
        $contextual = new QueryVariant(
            'corrected',
            'en',
            ['corrected'],
            QueryVariantSource::Contextual,
            500,
            'contextual',
            'original',
        );
        $variants = (new QueryVariantCollection(2, [$original, $low]))
            ->withPriorityReplacement($contextual);

        $suggestion = $this->evaluator($tokenizer)->evaluate(
            new SearchRankedCandidateCollection([]),
            $variants,
            true,
            'original',
        );

        $this->assertNull($suggestion);
        $this->assertTrue($variants->contains('original'));
        $this->assertTrue($variants->contains('contextual'));
        $this->assertCount(2, $variants);
    }

    public function test_semantic_duplicate_no_op_keeps_the_existing_suggestion_lineage_valid(): void
    {
        $tokenizer = new RecordingSearchTokenizer;
        $original = new QueryVariant(
            'original',
            'en',
            ['original'],
            QueryVariantSource::Original,
            1000,
            'original',
        );
        $existing = new QueryVariant(
            'corrected',
            'en',
            ['corrected'],
            QueryVariantSource::Spelling,
            700,
            'existing',
            'original',
        );
        $leaf = new QueryVariant(
            'leaf',
            'en',
            ['leaf'],
            QueryVariantSource::KeyboardSynonym,
            400,
            'leaf',
            'existing',
        );
        $duplicate = new QueryVariant(
            'corrected',
            'en',
            ['corrected'],
            QueryVariantSource::Contextual,
            500,
            'duplicate',
            'original',
        );
        $variants = (new QueryVariantCollection(3, [$original, $existing, $leaf]))
            ->withPriorityReplacement($duplicate);

        $suggestion = $this->evaluator($tokenizer)->evaluate(
            new SearchRankedCandidateCollection([]),
            $variants,
            true,
            'original',
        );

        $this->assertNull($suggestion);
        $this->assertSame(['original', 'existing', 'leaf'], array_map(
            static fn (QueryVariant $variant): string => $variant->fingerprint,
            $variants->all(),
        ));
    }

    private function evaluator(RecordingSearchTokenizer $tokenizer): EffectiveSearchSuggestionEvaluator
    {
        return new EffectiveSearchSuggestionEvaluator(
            new SearchSuggestionPolicy(true, true, 1, 2, 15000),
            new SearchRankMatcher($tokenizer, app(SearchRankingPolicy::class)),
        );
    }

    private function variant(
        string $fingerprint,
        QueryVariantSource $source,
        ?string $parent = null,
    ): QueryVariant {
        return new QueryVariant($fingerprint, 'en', [$fingerprint], $source, 1000, $fingerprint, $parent);
    }

    private function ranked(QueryVariant $variant): SearchRankedCandidate
    {
        $record = new SearchDocumentRecord;
        $record->setRawAttributes([
            'id' => '1',
            'partition' => 'default',
            'source_key' => 'page:one',
            'source_type' => 'page',
            'source_id' => null,
            'locale' => 'en',
            'normalized_title' => 'original',
            'priority' => 0,
            'is_active' => true,
        ], true);
        $match = new SearchCandidateMatch($variant, [SearchDocumentField::Title], [$variant->query]);
        $candidate = SearchCandidate::fromMatch($record, $match);
        $rank = new SearchRank(
            SearchRankTier::ExactTitle,
            1400,
            $variant,
            SearchDocumentField::Title,
            [$variant->query],
            1,
            1,
            10000,
        );

        return new SearchRankedCandidate($candidate, $rank);
    }
}

final class RecordingSearchTokenizer implements SearchTokenizer
{
    public int $calls = 0;

    public function tokenize(string $normalizedText, string $locale): array
    {
        $this->calls++;

        return preg_split('/\s+/u', $normalizedText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
