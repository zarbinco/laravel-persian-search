<?php

namespace Zarbinco\PersianSearch\Search;

use Zarbinco\PersianSearch\Exceptions\SearchLocaleBridgeIdentityConflictException;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidateCollection;

final readonly class SearchLocaleBridge
{
    public function __construct(
        private SearchLocaleBridgePolicy $policy,
        private SearchLocaleCounterpartLookup $counterparts,
    ) {}

    public function bridge(
        SearchRankedCandidateCollection $ranked,
        SearchQuery $query,
    ): SearchPresentedCandidateCollection {
        $requested = $query->processedQuery->locale;
        $pairs = [];

        if ($this->policy->enabled) {
            foreach ($ranked as $candidate) {
                $document = $candidate->candidate->document;

                if ($document->locale !== $requested) {
                    $pairs[SearchLocaleCounterpartIdentity::key($document->partition, $document->source_key)] = [
                        'partition' => $document->partition,
                        'source_key' => $document->source_key,
                    ];
                }
            }
        }

        $counterparts = $this->counterparts->find(array_values($pairs), $requested);

        $presented = [];

        foreach ($ranked as $candidate) {
            $matched = $candidate->candidate->document;

            if ($matched->locale === $requested) {
                $document = $matched;
                $status = SearchLocaleBridgeStatus::NotRequired;
            } elseif (! $this->policy->enabled) {
                $document = $matched;
                $status = SearchLocaleBridgeStatus::Disabled;
            } else {
                $document = $counterparts[
                    SearchLocaleCounterpartIdentity::key($matched->partition, $matched->source_key)
                ] ?? null;

                if ($document !== null
                    && ($document->source_type !== $matched->source_type || $document->source_id !== $matched->source_id)) {
                    throw SearchLocaleBridgeIdentityConflictException::forIdentity(
                        $matched->partition,
                        $matched->source_key,
                        $requested,
                    );
                }

                $status = $document === null
                    ? SearchLocaleBridgeStatus::CounterpartMissing
                    : SearchLocaleBridgeStatus::Bridged;
                $document ??= $matched;
            }

            $presented[] = new SearchPresentedCandidate(
                $candidate,
                $document,
                new SearchLocaleBridgeMetadata(
                    $status,
                    $requested,
                    $candidate->rank->variant->locale,
                    $document->locale,
                ),
            );
        }

        return new SearchPresentedCandidateCollection($presented);
    }
}
