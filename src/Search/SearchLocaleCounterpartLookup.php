<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Database\Eloquent\Builder;
use Zarbinco\PersianSearch\Exceptions\DuplicateSearchLocaleCounterpartException;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;

final readonly class SearchLocaleCounterpartLookup
{
    public function __construct(private SearchLocaleBridgePolicy $policy) {}

    /**
     * @param  array<int, array{partition: string, source_key: string}>  $pairs
     * @return array<string, SearchDocumentRecord>
     */
    public function find(array $pairs, string $requestedLocale): array
    {
        $expected = [];

        foreach ($pairs as $pair) {
            $key = SearchLocaleCounterpartIdentity::key($pair['partition'], $pair['source_key']);
            $expected[$key] ??= $pair;
        }

        $pairs = array_values($expected);
        usort($pairs, static fn (array $left, array $right): int => strcmp($left['partition'], $right['partition'])
            ?: strcmp($left['source_key'], $right['source_key']));
        $counterparts = [];

        foreach (array_chunk($pairs, $this->policy->batchSize) as $batch) {
            $records = SearchDocumentRecord::query()
                ->where('locale', SearchDocumentRecord::localeStorageKey($requestedLocale))
                ->where('is_active', true)
                ->where(function (Builder $query) use ($batch): void {
                    foreach ($batch as $pair) {
                        $query->orWhere(function (Builder $pairQuery) use ($pair): void {
                            $pairQuery->where('partition', $pair['partition'])
                                ->where('source_key', $pair['source_key']);
                        });
                    }
                })
                ->get();

            foreach ($records as $record) {
                if ($record->locale !== $requestedLocale) {
                    continue;
                }

                $key = SearchLocaleCounterpartIdentity::key($record->partition, $record->source_key);
                $pair = $expected[$key] ?? null;

                if ($pair === null
                    || $record->partition !== $pair['partition']
                    || $record->source_key !== $pair['source_key']) {
                    continue;
                }

                $existing = $counterparts[$key] ?? null;

                if ($existing !== null && (string) $existing->getKey() !== (string) $record->getKey()) {
                    throw DuplicateSearchLocaleCounterpartException::forIdentity(
                        $pair['partition'],
                        $pair['source_key'],
                        $requestedLocale,
                    );
                }

                $counterparts[$key] = $record;
            }
        }

        return $counterparts;
    }
}
