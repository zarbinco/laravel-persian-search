<?php

namespace Zarbinco\PersianSearch\Spelling;

use Illuminate\Database\DatabaseManager;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;

final readonly class SpellingDictionaryStatusService
{
    public function __construct(
        private SpellingPolicy $policy,
        private DatabaseManager $database,
    ) {}

    public function snapshot(): SpellingDictionaryStatus
    {
        $connectionName = $this->policy->connection ?? config('persian-search.index.connection');
        $connection = $this->database->connection($connectionName);
        $schema = $connection->getSchemaBuilder();
        $termsExist = $schema->hasTable($this->policy->termsTable);
        $deletesExist = $schema->hasTable($this->policy->deletesTable);
        $terms = $termsExist ? $connection->table($this->policy->termsTable)->count() : 0;
        $deletes = $deletesExist ? $connection->table($this->policy->deletesTable)->count() : 0;
        $localeCounts = [];
        $dictionaryBuiltByLocale = [];

        if ($termsExist) {
            foreach ($connection->table($this->policy->termsTable)
                ->select('locale')
                ->selectRaw('COUNT(*) as aggregate')
                ->selectRaw('MAX(indexed_at) as last_built_at')
                ->groupBy('locale')
                ->orderBy('locale')
                ->get() as $row) {
                $locale = (string) $row->locale;
                $localeCounts[$locale] = (int) $row->aggregate;
                $dictionaryBuiltByLocale[$locale] = $row->last_built_at === null ? null : (string) $row->last_built_at;
            }
        }

        $document = new SearchDocumentRecord;
        $documentSchema = $document->getConnection()->getSchemaBuilder();
        $documentsByLocale = [];
        if ($documentSchema->hasTable($document->getTable())) {
            foreach ($document->getConnection()->table($document->getTable())
                ->where('is_active', true)
                ->select('locale')
                ->selectRaw('MAX(indexed_at) as latest_indexed_at')
                ->groupBy('locale')
                ->orderBy('locale')
                ->get() as $row) {
                $documentsByLocale[(string) $row->locale] = $row->latest_indexed_at === null
                    ? null
                    : (string) $row->latest_indexed_at;
            }
        }

        $stale = false;
        foreach ($documentsByLocale as $locale => $latest) {
            $built = $dictionaryBuiltByLocale[$locale] ?? null;
            if ($built === null || ($latest !== null && $latest > $built)) {
                $stale = true;

                break;
            }
        }

        $lastBuilt = $this->maximumTimestamp($dictionaryBuiltByLocale);
        $latestDocument = $this->maximumTimestamp($documentsByLocale);

        return new SpellingDictionaryStatus(
            enabled: $this->policy->enabled,
            connection: is_string($connectionName) && trim($connectionName) !== '' ? $connectionName : null,
            termsTable: $this->policy->termsTable,
            deletesTable: $this->policy->deletesTable,
            termsTableExists: $termsExist,
            deletesTableExists: $deletesExist,
            terms: (int) $terms,
            deletes: (int) $deletes,
            localeTermCounts: $localeCounts,
            lastBuiltAt: $lastBuilt,
            latestDocumentIndexedAt: $latestDocument,
            stale: $stale,
        );
    }

    /** @param array<string, string|null> $timestamps */
    private function maximumTimestamp(array $timestamps): ?string
    {
        $values = array_values(array_filter($timestamps, static fn (?string $value): bool => $value !== null));
        if ($values === []) {
            return null;
        }
        rsort($values, SORT_STRING);

        return $values[0];
    }
}
