<?php

namespace Zarbinco\PersianSearch\Spelling;

use Illuminate\Database\DatabaseManager;
use Zarbinco\PersianSearch\Contextual\ContextualCorrectionPolicy;
use Zarbinco\PersianSearch\Correction\AdvancedCorrectionPolicy;
use Zarbinco\PersianSearch\Correction\LanguageCorrectionProfileRegistry;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;

final readonly class SpellingDictionaryStatusService
{
    public function __construct(
        private SpellingPolicy $policy,
        private DatabaseManager $database,
        private ?AdvancedCorrectionPolicy $advanced = null,
        private ?LanguageCorrectionProfileRegistry $profiles = null,
        private ?ContextualCorrectionPolicy $contextual = null,
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
        $documentCountsByLocale = [];
        if ($documentSchema->hasTable($document->getTable())) {
            foreach ($document->getConnection()->table($document->getTable())
                ->where('is_active', true)
                ->select('locale')
                ->selectRaw('COUNT(*) as aggregate')
                ->selectRaw('MAX(indexed_at) as latest_indexed_at')
                ->groupBy('locale')
                ->orderBy('locale')
                ->get() as $row) {
                $locale = (string) $row->locale;
                $documentCountsByLocale[$locale] = (int) $row->aggregate;
                $documentsByLocale[$locale] = $row->latest_indexed_at === null
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
        $supportedProfiles = $this->profiles?->locales() ?? [];
        $advancedEnabled = $this->advanced?->enabled() ?? false;
        $enabledProfiles = $advancedEnabled ? $supportedProfiles : [];
        $dictionaryReady = $termsExist && $terms > 0;
        $warnings = [];
        if ($advancedEnabled && ! $dictionaryReady) {
            $warnings[] = 'Advanced correction is enabled but the term dictionary is not ready.';
        }
        $ngramsTable = $this->contextual?->ngramsTable;
        $buildsTable = $this->contextual?->buildsTable;
        $contextualConnectionName = $this->contextual->connection ?? $connectionName;
        $contextualConnection = $this->database->connection($contextualConnectionName);
        $contextualSchema = $contextualConnection->getSchemaBuilder();
        $ngramsExist = $ngramsTable !== null && $contextualSchema->hasTable($ngramsTable);
        $buildsExist = $buildsTable !== null && $contextualSchema->hasTable($buildsTable);
        $ngrams = $ngramsExist ? (int) $contextualConnection->table($ngramsTable)->count() : 0;
        $localeNgramCounts = [];
        $ngramBuiltByLocale = [];
        $generationReadyByLocale = [];
        $buildTermCounts = [];
        $buildDocumentCounts = [];
        if ($buildsExist) {
            foreach ($contextualConnection->table($buildsTable)
                ->select([
                    'locale',
                    'dictionary_generation',
                    'ngram_generation',
                    'term_count',
                    'document_count',
                    'ngram_count',
                    'ngram_indexed_at',
                ])
                ->orderBy('locale')
                ->get() as $row) {
                $locale = (string) $row->locale;
                $localeNgramCounts[$locale] = (int) $row->ngram_count;
                $ngramBuiltByLocale[$locale] = $row->ngram_indexed_at === null
                    ? null
                    : (string) $row->ngram_indexed_at;
                $generationReadyByLocale[$locale] = (string) $row->dictionary_generation !== ''
                    && $row->ngram_generation !== null
                    && (string) $row->dictionary_generation === (string) $row->ngram_generation
                    && $row->ngram_indexed_at !== null;
                $buildTermCounts[$locale] = (int) $row->term_count;
                $buildDocumentCounts[$locale] = (int) $row->document_count;
            }
        }
        $contextualEnabled = $this->contextual !== null && $this->contextual->enabled;
        $ngramsEnabled = $this->contextual !== null && $this->contextual->ngramsEnabled;
        $contextualReadyByLocale = [];
        $contextualLocales = array_values(array_unique([
            ...array_keys($localeCounts),
            ...array_keys($documentsByLocale),
        ]));
        foreach ($contextualLocales as $locale) {
            $termBuilt = $dictionaryBuiltByLocale[$locale] ?? null;
            $documentBuilt = $documentsByLocale[$locale] ?? null;
            $termCount = array_key_exists($locale, $localeCounts) ? $localeCounts[$locale] : 0;
            $buildTermCount = array_key_exists($locale, $buildTermCounts) ? $buildTermCounts[$locale] : -1;
            $buildDocumentCount = array_key_exists($locale, $buildDocumentCounts)
                ? $buildDocumentCounts[$locale]
                : -1;
            $documentCount = array_key_exists($locale, $documentCountsByLocale)
                ? $documentCountsByLocale[$locale]
                : 0;
            $dictionaryLocaleReady = $termCount > 0
                && $termBuilt !== null
                && ($documentBuilt === null || $termBuilt >= $documentBuilt)
                && $buildTermCount === $termCount
                && $buildDocumentCount === $documentCount;
            $ngramBuilt = $ngramBuiltByLocale[$locale] ?? null;
            $contextualReadyByLocale[$locale] = $dictionaryLocaleReady
                && (! $ngramsEnabled
                    || (($generationReadyByLocale[$locale] ?? false)
                        && $ngramBuilt !== null
                        && $ngramBuilt >= $termBuilt
                        && ($documentBuilt === null || $ngramBuilt >= $documentBuilt)));
        }
        $contextualReady = $contextualEnabled
            && $dictionaryReady
            && $contextualReadyByLocale !== []
            && ! in_array(false, $contextualReadyByLocale, true);
        if ($contextualEnabled && ! $contextualReady) {
            $warnings[] = 'Contextual correction is enabled but its dictionary or n-gram evidence is not ready.';
        }

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
            supportedProfiles: $supportedProfiles,
            enabledProfiles: $enabledProfiles,
            phoneticReady: ($this->advanced->phoneticEnabled ?? false) && $dictionaryReady,
            splitReady: ($this->advanced->segmentationEnabled ?? false)
                && ($this->advanced->splitEnabled ?? false)
                && $dictionaryReady,
            mergeReady: ($this->advanced->segmentationEnabled ?? false)
                && ($this->advanced->mergeEnabled ?? false)
                && $dictionaryReady,
            warnings: $warnings,
            ngramsTable: $ngramsTable,
            buildsTable: $buildsTable,
            ngramsTableExists: $ngramsExist,
            buildsTableExists: $buildsExist,
            ngrams: $ngrams,
            contextualReady: $contextualReady,
            localeNgramCounts: $localeNgramCounts,
            ngramBuiltByLocale: $ngramBuiltByLocale,
            contextualReadyByLocale: $contextualReadyByLocale,
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
