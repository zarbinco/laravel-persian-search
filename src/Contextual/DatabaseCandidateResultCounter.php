<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use Zarbinco\PersianSearch\Candidates\SearchCandidateCollection;
use Zarbinco\PersianSearch\Contracts\CandidateResultCounter;
use Zarbinco\PersianSearch\Contracts\QueryVariantResultCounter;
use Zarbinco\PersianSearch\Contracts\SearchCandidateDriver;
use Zarbinco\PersianSearch\Contracts\SearchRanker;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Query\QueryVariantPolicy;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidateCollection;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Search\SearchQuery;

final class DatabaseCandidateResultCounter implements CandidateResultCounter, QueryVariantResultCounter
{
    /** @var array<string, CandidateResultCount> */
    private array $cache = [];

    public function __construct(
        private readonly ContextualCorrectionPolicy $policy,
        private readonly SearchCandidateDriver $candidates,
        private readonly SearchRanker $ranker,
        private readonly QueryVariantPolicy $variants,
    ) {}

    public function reset(): void
    {
        $this->cache = [];
    }

    public function directCount(
        SearchQuery $query,
        SearchRankedCandidateCollection $ranked,
        bool $exact,
    ): CandidateResultCount {
        $original = $query->variants()->original();
        if ($original === null) {
            return new CandidateResultCount(0, ! $exact, count($ranked));
        }
        $count = 0;
        foreach ($ranked as $rankedCandidate) {
            foreach ($rankedCandidate->candidate->matches as $match) {
                if ($match->variant->fingerprint === $original->fingerprint
                    && $this->hasFullTokenCoverage($original, $match->terms)) {
                    $count++;

                    break;
                }
            }
        }
        $capped = min($count, $this->policy->resultCountCap);

        return new CandidateResultCount(
            $capped,
            ! $exact || $count > $this->policy->resultCountCap,
            count($ranked),
        );
    }

    public function countResults(ContextualCandidate $candidate, SearchQuery $query): CandidateResultCount
    {
        $variant = new QueryVariant(
            query: $candidate->correctedQuery,
            locale: $candidate->locale,
            tokens: $candidate->tokens,
            source: QueryVariantSource::Contextual,
            priority: $this->variants->priority(QueryVariantSource::Contextual),
            fingerprint: $candidate->fingerprint,
        );

        return $this->countVariant($variant, $query);
    }

    public function countVariant(QueryVariant $variant, SearchQuery $query): CandidateResultCount
    {
        $key = hash('sha256', implode("\0", [
            $variant->locale,
            $variant->query,
            $query->partition,
            implode("\0", $query->sourceTypes),
        ]));
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $candidateQuery = new SearchQuery(
            original: $query->original,
            normalized: $variant->query,
            tokens: $variant->tokens,
            sourceTypes: $query->sourceTypes,
            locale: $variant->locale,
            partition: $query->partition,
            limit: $query->limit,
            offset: $query->offset,
            processedQuery: $query->processedQuery,
            variants: new QueryVariantCollection(1, [$variant]),
            facetFields: $query->facetFields,
        );
        $retrieval = $this->candidates->candidates($candidateQuery);
        $validCandidates = new SearchCandidateCollection(max(1, $retrieval->candidateLimit));
        foreach ($retrieval as $retrievedCandidate) {
            $document = $retrievedCandidate->document;
            if (! $document->is_active || $document->locale !== $variant->locale
                || ! $this->validUtf8Document($document)) {
                continue;
            }
            $validCandidates = $validCandidates->with($retrievedCandidate);
        }
        $ranked = $this->ranker->rank($validCandidates);
        $count = 0;
        foreach ($ranked as $rankedCandidate) {
            foreach ($rankedCandidate->candidate->matches as $match) {
                if ($match->variant->fingerprint === $variant->fingerprint
                    && $this->hasFullTokenCoverage($variant, $match->terms)) {
                    $count++;

                    break;
                }
            }
        }
        $capped = min($count, $this->policy->resultCountCap);

        return $this->cache[$key] = new CandidateResultCount(
            count: $capped,
            isApproximate: $retrieval->isTruncated() || $count > $this->policy->resultCountCap,
            examinedCandidates: count($retrieval),
        );
    }

    /** @param list<string> $matchedTerms */
    private function hasFullTokenCoverage(QueryVariant $variant, array $matchedTerms): bool
    {
        $required = array_fill_keys($variant->tokens, true);
        foreach ($matchedTerms as $term) {
            unset($required[$term]);
        }

        return $required === [];
    }

    private function validUtf8Document(SearchDocumentRecord $document): bool
    {
        foreach ([
            $document->normalized_title,
            $document->normalized_keywords,
            $document->normalized_excerpt,
            $document->normalized_content,
        ] as $value) {
            if ($value !== null && preg_match('//u', $value) !== 1) {
                return false;
            }
        }

        return true;
    }
}
