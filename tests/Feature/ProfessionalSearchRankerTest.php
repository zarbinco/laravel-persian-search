<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Zarbinco\PersianSearch\Candidates\SearchCandidate;
use Zarbinco\PersianSearch\Candidates\SearchCandidateCollection;
use Zarbinco\PersianSearch\Candidates\SearchCandidateMatch;
use Zarbinco\PersianSearch\Candidates\SearchDocumentField;
use Zarbinco\PersianSearch\Contracts\SearchRanker;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\ProfessionalSearchRanker;
use Zarbinco\PersianSearch\Ranking\SearchRankTier;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Tests\TestCase;

final class ProfessionalSearchRankerTest extends TestCase
{
    public function test_professional_search_ranker_performs_no_database_or_hydration_queries(): void
    {
        $variant = new QueryVariant(
            'orange',
            'en',
            ['orange'],
            QueryVariantSource::Original,
            1000,
            'original',
        );
        $record = new SearchDocumentRecord;
        $record->setRawAttributes([
            'id' => 1,
            'source_key' => 'virtual:orange',
            'source_type' => 'virtual',
            'source_id' => null,
            'partition' => 'public',
            'locale' => 'en',
            'priority' => 0,
            'normalized_title' => 'orange',
            'normalized_keywords' => null,
            'normalized_excerpt' => null,
            'normalized_content' => null,
        ], true);
        $match = new SearchCandidateMatch($variant, [SearchDocumentField::Title], ['orange']);
        $candidates = (new SearchCandidateCollection(10))->with(SearchCandidate::fromMatch($record, $match));
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $ranker = app(SearchRanker::class);
        $first = $ranker->rank($candidates);
        $second = $ranker->rank($candidates);

        $this->assertInstanceOf(ProfessionalSearchRanker::class, $ranker);
        $this->assertSame(0, $queries);
        $this->assertCount(1, $first);
        $this->assertSame(SearchRankTier::ExactTitle, $first->all()[0]->rank->tier);
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertNull($first->all()[0]->candidate->document->source_id);
    }
}
