<?php

namespace Zarbinco\PersianSearch\Operations;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchSourceEnumeratorException;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

final readonly class SearchPruneOperation
{
    public function __construct(
        private SearchSourceEnumeratorRegistry $enumerators,
        private SearchOperationsPolicy $policy,
        private SearchMaintenanceLockManager $locks,
    ) {}

    public function run(SearchPruneRequest $request): SearchPruneReport
    {
        $selectedAll = $this->enumerators->selected($request->enumeratorKeys(), $request->providerKeys());
        $selected = array_values(array_filter($selectedAll, static fn ($item): bool => $item->authoritative));
        if ($request->enumeratorKeys() !== [] && count($selected) !== count($selectedAll)) {
            throw new InvalidSearchSourceEnumeratorException('Only authoritative source enumerators may be selected for pruning.');
        }

        $providers = $request->providerKeys();
        if ($providers === []) {
            $providers = array_values(array_unique(array_column($selected, 'providerKey')));
            usort($providers, strcmp(...));
        }
        foreach ($providers as $provider) {
            if (! in_array($provider, array_column($selected, 'providerKey'), true)) {
                throw new InvalidSearchSourceEnumeratorException(
                    "Search document provider [{$provider}] has no selected authoritative enumerator.",
                );
            }
        }

        $lock = $request->execute ? $this->locks->acquire() : null;
        try {
            $current = $this->currentReferences($selected, $request);
            [$persisted, $currentDocuments] = $this->persistedReferences($providers, $current);
            $orphans = array_diff_key($persisted, $current);
            $orphanDocuments = array_sum(array_column($orphans, 'documents'));
            $deletedReferences = 0;
            $deletedDocuments = 0;

            if ($request->execute) {
                foreach ($orphans as $orphan) {
                    $deletedDocuments += $this->deleteReference($orphan);
                    $deletedReferences++;
                }
            }

            return new SearchPruneReport(
                $request->execute,
                count($providers),
                count($selected),
                count($current),
                count($persisted),
                $currentDocuments,
                count($orphans),
                $orphanDocuments,
                $deletedReferences,
                $deletedDocuments,
            );
        } finally {
            $lock?->release();
        }
    }

    /**
     * @param  list<SearchSourceEnumeratorRegistration>  $selected
     * @return array<string, true>
     */
    private function currentReferences(array $selected, SearchPruneRequest $request): array
    {
        $context = new SearchSourceEnumerationContext(
            $this->policy->chunkSize,
            null,
            $request->enumeratorKeys(),
            $request->providerKeys(),
            ! $request->execute,
        );
        $maximum = min(
            $this->policy->maximumSourcesPerRun,
            $request->limit ?? $this->policy->maximumSourcesPerRun,
        );
        $collection = new SearchSourceOwnershipCollection($maximum);
        foreach ($selected as $registration) {
            foreach ($registration->enumerator->enumerate($context) as $locator) {
                $collection->add($locator, $registration->providerKey);
            }
        }

        $current = [];
        foreach ($collection->all() as $reference) {
            $current[$reference->fingerprint()] = true;
        }

        return $current;
    }

    /**
     * @param  list<string>  $providers
     * @param  array<string, true>  $current
     * @return array{array<string, array<string, mixed>>, int}
     */
    private function persistedReferences(array $providers, array $current): array
    {
        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        $ownership = [];
        $currentDocuments = 0;
        if ($providers === []) {
            return [$groups, 0];
        }

        SearchDocumentRecord::query()
            ->whereIn('provider_key', $providers)
            ->select(['id', 'provider_key', 'partition', 'source_key', 'source_type', 'source_id'])
            ->orderBy('id')
            ->chunkById($this->policy->chunkSize, function ($rows) use (&$groups, &$ownership, &$currentDocuments, $current): void {
                foreach ($rows as $record) {
                    $reference = new SearchSourceOwnershipReference(
                        $record->provider_key,
                        $record->partition,
                        new SearchSourceReference(
                            $record->source_key,
                            $record->source_type,
                            $record->source_id,
                        ),
                    );
                    $key = $reference->fingerprint();
                    $owner = $reference->scopeFingerprint();
                    if (isset($ownership[$owner]) && $ownership[$owner] !== $key) {
                        throw new RuntimeException('Conflicting persisted Persian search source ownership was detected.');
                    }
                    $ownership[$owner] = $key;
                    $groups[$key] ??= [
                        'provider_key' => $record->provider_key,
                        'partition' => $record->partition,
                        'source_key' => $record->source_key,
                        'source_type' => $record->source_type,
                        'source_id' => $record->source_id,
                        'documents' => 0,
                    ];
                    $groups[$key]['documents']++;
                    if (isset($current[$key])) {
                        $currentDocuments++;
                    }
                }
            });
        ksort($groups, SORT_STRING);

        return [$groups, $currentDocuments];
    }

    /** @param array<string, mixed> $reference */
    private function deleteReference(array $reference): int
    {
        $record = new SearchDocumentRecord;
        $connection = $record->getConnection();

        return $connection->transaction(function () use ($reference): int {
            /** @var Builder<SearchDocumentRecord> $query */
            $query = SearchDocumentRecord::query()
                ->where('provider_key', $reference['provider_key'])
                ->where('partition', $reference['partition'])
                ->where('source_key', $reference['source_key'])
                ->where('source_type', $reference['source_type']);
            $reference['source_id'] === null
                ? $query->whereNull('source_id')
                : $query->where('source_id', $reference['source_id']);

            return $query->delete();
        });
    }
}
