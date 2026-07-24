<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Candidates\SearchCandidate;
use Zarbinco\PersianSearch\Candidates\SearchCandidateMatch;
use Zarbinco\PersianSearch\Candidates\SearchDocumentField;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\SearchRank;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidate;
use Zarbinco\PersianSearch\Ranking\SearchRankTier;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgeMetadata;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgeStatus;
use Zarbinco\PersianSearch\Search\SearchPresentedCandidate;
use Zarbinco\PersianSearch\Search\SearchResult;

final class SearchPresentedPersistenceIntegrityTest extends TestCase
{
    public function test_persisted_integrity_rejects_matched_document_with_key_but_exists_false(): void
    {
        $matched = $this->ranked($this->record('1', 'fa', false), 'fa');
        $presented = $this->record('1', 'fa', true);
        $bridge = new SearchLocaleBridgeMetadata(SearchLocaleBridgeStatus::NotRequired, 'fa', 'fa', 'fa');

        $this->expectException(InvalidArgumentException::class);
        new SearchPresentedCandidate($matched, $presented, $bridge);
    }

    public function test_persisted_integrity_rejects_presented_document_with_key_but_exists_false(): void
    {
        $matched = $this->ranked($this->record('1', 'fa', true), 'fa');
        $presented = $this->record('1', 'fa', false);
        $bridge = new SearchLocaleBridgeMetadata(SearchLocaleBridgeStatus::NotRequired, 'fa', 'fa', 'fa');

        $this->expectException(InvalidArgumentException::class);
        new SearchPresentedCandidate($matched, $presented, $bridge);
    }

    public function test_search_result_rejects_record_with_key_but_exists_false(): void
    {
        $ranked = $this->ranked($this->record('1', 'fa', true), 'fa');
        $record = $this->record('1', 'fa', false);
        $bridge = new SearchLocaleBridgeMetadata(SearchLocaleBridgeStatus::NotRequired, 'fa', 'fa', 'fa');

        $this->expectException(InvalidArgumentException::class);
        new SearchResult($record, null, $ranked->rank, $bridge);
    }

    public function test_persisted_matched_and_presented_records_are_accepted_without_a_source_model(): void
    {
        $matchedRecord = $this->record('1', 'fa', true);
        $ranked = $this->ranked($matchedRecord, 'fa');
        $bridge = new SearchLocaleBridgeMetadata(SearchLocaleBridgeStatus::NotRequired, 'fa', 'fa', 'fa');
        $candidate = new SearchPresentedCandidate($ranked, $matchedRecord, $bridge);
        $result = new SearchResult($matchedRecord, null, $ranked->rank, $bridge);

        $this->assertSame('1', $candidate->identity());
        $this->assertNull($result->model);
    }

    public function test_matched_locale_disagreement_is_rejected_for_bridged_candidate(): void
    {
        $ranked = $this->ranked($this->record('1', 'ar', true), 'fa');
        $presented = $this->record('2', 'en', true);
        $bridge = new SearchLocaleBridgeMetadata(SearchLocaleBridgeStatus::Bridged, 'en', 'fa', 'en');

        $this->expectException(InvalidArgumentException::class);
        new SearchPresentedCandidate($ranked, $presented, $bridge);
    }

    public function test_matched_locale_exact_agreement_is_accepted(): void
    {
        $ranked = $this->ranked($this->record('1', 'fa', true), 'fa');
        $presented = $this->record('2', 'en', true);
        $bridge = new SearchLocaleBridgeMetadata(SearchLocaleBridgeStatus::Bridged, 'en', 'fa', 'en');

        $candidate = new SearchPresentedCandidate($ranked, $presented, $bridge);

        $this->assertSame('fa', $candidate->matchedCandidate->candidate->document->locale);
        $this->assertSame('en', $candidate->presentedDocument->locale);
    }

    public function test_matched_locale_fa_and_fa_ir_remain_distinct(): void
    {
        $ranked = $this->ranked($this->record('1', 'fa_IR', true), 'fa');
        $presented = $this->record('2', 'en', true);
        $bridge = new SearchLocaleBridgeMetadata(SearchLocaleBridgeStatus::Bridged, 'en', 'fa', 'en');

        $this->expectException(InvalidArgumentException::class);
        new SearchPresentedCandidate($ranked, $presented, $bridge);
    }

    private function ranked(SearchDocumentRecord $record, string $variantLocale): SearchRankedCandidate
    {
        $variant = new QueryVariant(
            'orange',
            $variantLocale,
            ['orange'],
            QueryVariantSource::Keyboard,
            800,
            'keyboard',
        );
        $match = new SearchCandidateMatch($variant, [SearchDocumentField::Title], ['orange']);
        $candidate = SearchCandidate::fromMatch($record, $match);
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

    private function record(string $id, string $locale, bool $exists): SearchDocumentRecord
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
            'is_active' => true,
        ], true);
        $record->exists = $exists;

        return $record;
    }
}
