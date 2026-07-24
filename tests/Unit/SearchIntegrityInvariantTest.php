<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Candidates\SearchCandidate;
use Zarbinco\PersianSearch\Candidates\SearchCandidateMatch;
use Zarbinco\PersianSearch\Candidates\SearchDocumentField;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchLocaleBridgeConfigurationException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchSuggestionConfigurationException;
use Zarbinco\PersianSearch\Exceptions\SearchLocaleBridgeIdentityConflictException;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\SearchRank;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidate;
use Zarbinco\PersianSearch\Ranking\SearchRankTier;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgeMetadata;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgePolicy;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgeStatus;
use Zarbinco\PersianSearch\Search\SearchPresentedCandidate;
use Zarbinco\PersianSearch\Search\SearchPresentedCandidateCollection;
use Zarbinco\PersianSearch\Search\SearchResult;
use Zarbinco\PersianSearch\Search\SearchSuggestionEvidence;
use Zarbinco\PersianSearch\Search\SearchSuggestionPolicy;
use Zarbinco\PersianSearch\Search\SearchSuggestionReason;

final class SearchIntegrityInvariantTest extends TestCase
{
    #[DataProvider('invalidBridgeBatchSizes')]
    public function test_direct_invalid_bridge_policy_construction_is_rejected(int $batchSize): void
    {
        $this->expectException(InvalidSearchLocaleBridgeConfigurationException::class);
        $policy = new SearchLocaleBridgePolicy(true, $batchSize);
        unset($policy);
    }

    /** @return array<string, array{int}> */
    public static function invalidBridgeBatchSizes(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'above maximum' => [1001]];
    }

    #[DataProvider('invalidSuggestionThresholds')]
    public function test_direct_invalid_suggestion_policy_construction_is_rejected(
        int $minimumResults,
        int $minimumGain,
        int $minimumRatio,
    ): void {
        $this->expectException(InvalidSearchSuggestionConfigurationException::class);
        new SearchSuggestionPolicy(true, true, $minimumResults, $minimumGain, $minimumRatio);
    }

    /** @return array<string, array{int, int, int}> */
    public static function invalidSuggestionThresholds(): array
    {
        return [
            'minimum results' => [0, 1, 10000],
            'minimum gain' => [1, 0, 10000],
            'minimum ratio' => [1, 1, 0],
            'results maximum' => [20001, 1, 10000],
            'gain maximum' => [1, 20001, 10000],
            'ratio maximum' => [1, 1, 1000001],
        ];
    }

    public function test_valid_policy_serialization_remains_deterministic(): void
    {
        $bridge = new SearchLocaleBridgePolicy(true, 200);
        $suggestion = new SearchSuggestionPolicy(true, true, 1, 2, 15000);

        $this->assertSame(['enabled' => true, 'batch_size' => 200], $bridge->toArray());
        $this->assertSame($bridge->toArray(), $bridge->toArray());
        $this->assertSame([
            'enabled' => true,
            'require_exact_window' => true,
            'minimum_results' => 1,
            'minimum_result_gain' => 2,
            'minimum_ratio_basis_points' => 15000,
        ], $suggestion->toArray());
        $this->assertSame($suggestion->toArray(), $suggestion->toArray());
    }

    #[DataProvider('invalidSuggestionEvidence')]
    public function test_search_suggestion_evidence_rejects_contradictory_reasons(
        int $originalCount,
        int $suggestedCount,
        int $gain,
        int $ratio,
        ?SearchRankTier $originalTier,
        SearchRankTier $suggestedTier,
        SearchSuggestionReason $reason,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new SearchSuggestionEvidence(
            $originalCount,
            $suggestedCount,
            $gain,
            $ratio,
            $originalTier,
            $suggestedTier,
            true,
            $reason,
        );
    }

    /** @return array<string, array{int, int, int, int, SearchRankTier|null, SearchRankTier, SearchSuggestionReason}> */
    public static function invalidSuggestionEvidence(): array
    {
        return [
            'better tier is worse' => [
                1, 1, 0, 10000, SearchRankTier::ExactTitle, SearchRankTier::ContentAnyToken,
                SearchSuggestionReason::BetterSemanticTier,
            ],
            'better tier is equal' => [
                1, 1, 0, 10000, SearchRankTier::TitlePhrase, SearchRankTier::TitlePhrase,
                SearchSuggestionReason::BetterSemanticTier,
            ],
            'material gain has equal counts' => [
                2, 2, 0, 10000, SearchRankTier::ContentAnyToken, SearchRankTier::ContentAnyToken,
                SearchSuggestionReason::MaterialResultGain,
            ],
        ];
    }

    public function test_valid_reason_specific_evidence_is_accepted(): void
    {
        $better = new SearchSuggestionEvidence(
            2,
            2,
            0,
            10000,
            SearchRankTier::ContentAnyToken,
            SearchRankTier::ExactTitle,
            true,
            SearchSuggestionReason::BetterSemanticTier,
        );
        $gain = new SearchSuggestionEvidence(
            2,
            3,
            1,
            15000,
            SearchRankTier::ExactTitle,
            SearchRankTier::ContentAnyToken,
            true,
            SearchSuggestionReason::MaterialResultGain,
        );
        $empty = new SearchSuggestionEvidence(
            0,
            1,
            1,
            0,
            null,
            SearchRankTier::ExactTitle,
            true,
            SearchSuggestionReason::OriginalHadNoResults,
        );

        $this->assertSame('better_semantic_tier', $better->toArray()['reason']);
        $this->assertSame('material_result_gain', $gain->toArray()['reason']);
        $this->assertSame('original_had_no_results', $empty->toArray()['reason']);
    }

    #[DataProvider('nonBridgedStatuses')]
    public function test_non_bridged_status_rejects_another_presented_document(SearchLocaleBridgeStatus $status): void
    {
        $ranked = $this->ranked('1', 'fa', true);
        $presented = $this->record('2', 'fa', true);
        $requested = $status === SearchLocaleBridgeStatus::NotRequired ? 'fa' : 'en';
        $metadata = new SearchLocaleBridgeMetadata($status, $requested, 'fa', 'fa');

        $this->expectException(InvalidArgumentException::class);
        new SearchPresentedCandidate($ranked, $presented, $metadata);
    }

    /** @return array<string, array{SearchLocaleBridgeStatus}> */
    public static function nonBridgedStatuses(): array
    {
        return [
            'not required' => [SearchLocaleBridgeStatus::NotRequired],
            'counterpart missing' => [SearchLocaleBridgeStatus::CounterpartMissing],
            'disabled' => [SearchLocaleBridgeStatus::Disabled],
        ];
    }

    public function test_bridged_inactive_or_same_document_is_rejected(): void
    {
        $ranked = $this->ranked('1', 'fa', true);
        $metadata = new SearchLocaleBridgeMetadata(SearchLocaleBridgeStatus::Bridged, 'en', 'fa', 'en');

        foreach ([$this->record('2', 'en', false), $this->record('1', 'en', true)] as $presented) {
            try {
                new SearchPresentedCandidate($ranked, $presented, $metadata);
                $this->fail('Invalid bridged presented document was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_valid_bridged_candidate_and_collection_serialization_are_deterministic(): void
    {
        $candidate = new SearchPresentedCandidate(
            $this->ranked('1', 'fa', true),
            $this->record('2', 'en', true),
            new SearchLocaleBridgeMetadata(SearchLocaleBridgeStatus::Bridged, 'en', 'fa', 'en'),
        );
        $collection = new SearchPresentedCandidateCollection([$candidate]);

        $this->assertSame([$candidate->toArray()], $collection->toArray());
        $this->assertSame($collection->toArray(), $collection->jsonSerialize());
    }

    public function test_search_result_rejects_record_and_rank_locale_disagreement(): void
    {
        $ranked = $this->ranked('1', 'fa', true);

        foreach ([
            [
                $this->record('2', 'fa', true),
                new SearchLocaleBridgeMetadata(SearchLocaleBridgeStatus::Bridged, 'en', 'fa', 'en'),
            ],
            [
                $this->record('2', 'en', true),
                new SearchLocaleBridgeMetadata(SearchLocaleBridgeStatus::Bridged, 'en', 'ar', 'en'),
            ],
        ] as [$record, $metadata]) {
            try {
                new SearchResult($record, null, $ranked->rank, $metadata);
                $this->fail('Contradictory search result was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_identity_conflict_message_redacts_raw_source_key_deterministically(): void
    {
        $sourceKey = "unsafe\nkey\u{202E}";
        $first = SearchLocaleBridgeIdentityConflictException::forIdentity('public', $sourceKey, 'en')->getMessage();
        $second = SearchLocaleBridgeIdentityConflictException::forIdentity('public', $sourceKey, 'en')->getMessage();

        $this->assertSame($first, $second);
        $this->assertStringNotContainsString($sourceKey, $first);
        $this->assertStringNotContainsString("\n", $first);
        $this->assertStringNotContainsString("\u{202E}", $first);
        $this->assertStringContainsString(hash('sha256', $sourceKey), $first);
        $this->assertStringContainsString('length ['.strlen($sourceKey).']', $first);
    }

    private function ranked(string $id, string $locale, bool $active): SearchRankedCandidate
    {
        $variant = new QueryVariant('orange', $locale, ['orange'], QueryVariantSource::Keyboard, 800, 'keyboard');
        $document = $this->record($id, $locale, $active);
        $match = new SearchCandidateMatch($variant, [SearchDocumentField::Title], ['orange']);
        $candidate = SearchCandidate::fromMatch($document, $match);
        $rank = new SearchRank(
            SearchRankTier::ExactTitle,
            1400,
            $variant,
            SearchDocumentField::Title,
            ['orange'],
            1,
            1,
            10000,
        );

        return new SearchRankedCandidate($candidate, $rank);
    }

    private function record(string $id, string $locale, bool $active): SearchDocumentRecord
    {
        $record = new SearchDocumentRecord;
        $record->setRawAttributes([
            'id' => $id,
            'partition' => 'public',
            'source_key' => 'page:orange',
            'source_type' => 'page',
            'source_id' => null,
            'locale' => $locale,
            'normalized_title' => 'orange',
            'priority' => 0,
            'is_active' => $active,
        ], true);

        return $record;
    }
}
