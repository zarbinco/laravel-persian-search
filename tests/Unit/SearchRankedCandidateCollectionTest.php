<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Candidates\SearchCandidate;
use Zarbinco\PersianSearch\Candidates\SearchCandidateMatch;
use Zarbinco\PersianSearch\Candidates\SearchDocumentField;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\SearchRank;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidate;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidateCollection;
use Zarbinco\PersianSearch\Ranking\SearchRankTier;
use Zarbinco\PersianSearch\Ranking\UnsignedDecimalStringComparator;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantSource;

final class SearchRankedCandidateCollectionTest extends TestCase
{
    #[DataProvider('unsignedIdentities')]
    public function test_identity_comparator_handles_large_unsigned_decimal_strings(
        string $left,
        string $right,
        int $expected,
    ): void {
        $this->assertSame($expected, UnsignedDecimalStringComparator::compare($left, $right));
        $this->assertSame(-$expected, UnsignedDecimalStringComparator::compare($right, $left));
    }

    /** @return array<string, array{string, string, -1|0|1}> */
    public static function unsignedIdentities(): array
    {
        return [
            '2 before 10' => ['2', '10', -1],
            '10 before 100' => ['10', '100', -1],
            'above PHP integer maximum' => ['9223372036854775808', '18446744073709551615', -1],
            'very long greater value' => ['99999999999999999999', '10000000000000000000', 1],
            'leading-zero textual tie-break' => ['0002', '2', -1],
            'exact equality' => ['18446744073709551615', '18446744073709551615', 0],
            'non-digit binary comparison' => ['id:10', 'id:2', -1],
            'digit versus non-digit binary comparison' => ['10', 'id:2', -1],
        ];
    }

    public function test_large_identity_order_is_independent_of_input_order_and_repeated_sorting(): void
    {
        $identities = [
            '18446744073709551615',
            '9223372036854775808',
            '100',
            '10',
            '2',
            '0002',
            '99999999999999999999999999999999999999',
        ];
        $forward = new SearchRankedCandidateCollection(array_map($this->ranked(...), $identities));
        $reverse = new SearchRankedCandidateCollection(array_map($this->ranked(...), array_reverse($identities)));
        $expected = ['0002', '2', '10', '100', '9223372036854775808', '18446744073709551615', '99999999999999999999999999999999999999'];

        $this->assertSame($expected, $this->identities($forward));
        $this->assertSame($expected, $this->identities($reverse));
        $this->assertSame($forward->toArray(), (new SearchRankedCandidateCollection($forward->all()))->toArray());

        $source = file_get_contents(__DIR__.'/../../src/Ranking/UnsignedDecimalStringComparator.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('(int)', $source);
        $this->assertStringNotContainsString('(float)', $source);
        $this->assertStringNotContainsString('bccomp', $source);
        $this->assertStringNotContainsString('gmp_', $source);
    }

    public function test_duplicate_identity_retains_better_rank_and_equal_rank_keeps_first(): void
    {
        $worse = $this->ranked('42', SearchRankTier::ContentAnyToken, 'worse');
        $better = $this->ranked('42', SearchRankTier::ExactTitle, 'better');

        $betterSecond = new SearchRankedCandidateCollection([$worse, $better]);
        $worseSecond = new SearchRankedCandidateCollection([$better, $worse]);
        $equalSecond = new SearchRankedCandidateCollection([$better, $this->ranked('42', SearchRankTier::ExactTitle, 'equal')]);

        $this->assertCount(1, $betterSecond);
        $this->assertSame('better', $betterSecond->all()[0]->candidate->matches[0]->terms[0]);
        $this->assertSame(SearchRankTier::ExactTitle, $betterSecond->all()[0]->rank->tier);
        $this->assertSame($better, $worseSecond->all()[0]);
        $this->assertSame($better, $equalSecond->all()[0]);
    }

    private function ranked(
        string $identity,
        SearchRankTier $tier = SearchRankTier::ContentAnyToken,
        string $evidence = 'orange',
    ): SearchRankedCandidate {
        $variant = new QueryVariant('orange', 'en', ['orange'], QueryVariantSource::Original, 1000, 'original');
        $record = new SearchDocumentRecord;
        $record->setRawAttributes([
            'id' => $identity,
            'source_key' => 'same',
            'partition' => 'same',
            'locale' => 'en',
            'priority' => 0,
            'normalized_title' => 'same title',
        ], true);
        $field = $tier === SearchRankTier::ExactTitle
            ? SearchDocumentField::Title
            : SearchDocumentField::Content;
        $match = new SearchCandidateMatch($variant, [$field], [$evidence]);
        $candidate = SearchCandidate::fromMatch($record, $match);
        $rank = new SearchRank($tier, $tier === SearchRankTier::ExactTitle ? 1400 : 400, $variant, $field, ['orange'], 1, 1, 10000);

        return new SearchRankedCandidate($candidate, $rank);
    }

    /** @return list<string> */
    private function identities(SearchRankedCandidateCollection $collection): array
    {
        return array_map(
            static fn (SearchRankedCandidate $candidate): string => $candidate->candidate->identity(),
            $collection->all(),
        );
    }
}
