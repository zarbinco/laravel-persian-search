<?php

namespace Zarbinco\PersianSearch\Spelling;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use RuntimeException;
use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final readonly class SpellingDictionaryBuilder
{
    public function __construct(
        private SpellingPolicy $policy,
        private SearchTokenizer $tokenizer,
        private SearchTextPipeline $pipeline,
        private SymmetricDeleteGenerator $deletes,
        private DatabaseManager $database,
    ) {}

    /** @param  list<string>  $locales */
    public function rebuild(array $locales = []): SpellingDictionaryBuildResult
    {
        $connection = $this->connection();
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable($this->policy->termsTable) || ! $schema->hasTable($this->policy->deletesTable)) {
            throw new RuntimeException('Persian search spelling dictionary tables do not exist. Run the package migrations first.');
        }

        $locales = $this->validatedLocales($locales);
        /**
         * @var array<string, array<string, array{
         *     document_frequency: int,
         *     title_frequency: int,
         *     keyword_frequency: int,
         *     excerpt_frequency: int,
         *     content_frequency: int
         * }>> $aggregate
         */
        $aggregate = [];
        $documents = 0;
        $uniqueTerms = 0;
        $query = SearchDocumentRecord::query()->where('is_active', true)->orderBy('id');
        if ($locales !== []) {
            $query->whereIn('locale', $locales);
        }

        $query->chunkById($this->policy->buildChunkSize, function ($records) use (&$aggregate, &$documents, &$uniqueTerms): void {
            foreach ($records as $record) {
                $locale = (string) $record->locale;
                $documentTerms = [];
                $fields = [
                    'title' => $record->normalized_title,
                    'keywords' => $record->normalized_keywords,
                    'excerpt' => $record->normalized_excerpt,
                    'content' => $record->normalized_content,
                ];

                foreach ($fields as $field => $value) {
                    if (! is_string($value) || $value === '') {
                        continue;
                    }
                    $tokens = $this->tokenizer->tokenize($value, $locale);
                    foreach ($tokens as $token) {
                        if (! $this->eligible($token)) {
                            continue;
                        }
                        $counts = $aggregate[$locale][$token] ?? null;
                        if ($counts === null) {
                            $counts = $this->emptyFrequencyCounts();
                            $uniqueTerms++;
                            if ($uniqueTerms > $this->policy->maximumDictionaryTerms) {
                                throw new RuntimeException('Persian search spelling dictionary exceeded its configured maximum term count.');
                            }
                        }
                        $documentTerms[$token] = true;

                        if ($field === 'title') {
                            $counts['title_frequency']++;
                        } elseif ($field === 'keywords') {
                            $counts['keyword_frequency']++;
                        } elseif ($field === 'excerpt') {
                            $counts['excerpt_frequency']++;
                        } else {
                            $counts['content_frequency']++;
                        }

                        $aggregate[$locale][$token] = $counts;
                    }
                }

                foreach (array_keys($documentTerms) as $token) {
                    $counts = $aggregate[$locale][$token] ?? $this->emptyFrequencyCounts();
                    $counts['document_frequency']++;
                    $aggregate[$locale][$token] = $counts;
                }
                $documents++;
            }
        });

        $now = Carbon::now();
        $localeTermCounts = [];
        $termRows = [];
        foreach ($aggregate as $locale => $terms) {
            $protected = $this->normalizedProtectedTerms($locale);
            ksort($terms, SORT_STRING);
            foreach ($terms as $term => $counts) {
                $isProtected = isset($protected[$term]);
                if (! $isProtected && $counts['document_frequency'] < $this->policy->minimumDocumentFrequency) {
                    continue;
                }
                $termRows[] = [
                    'locale' => $locale,
                    'term' => $term,
                    'normalized_term' => $term,
                    ...$counts,
                    'is_protected' => $isProtected,
                    'indexed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $localeTermCounts[$locale] = ($localeTermCounts[$locale] ?? 0) + 1;
            }
        }
        ksort($localeTermCounts, SORT_STRING);

        /** @var positive-int $insertBatchSize */
        $insertBatchSize = max(1, $this->policy->insertBatchSize);

        $deleteCount = $connection->transaction(function () use ($connection, $insertBatchSize, $locales, $termRows): int {
            $this->deleteExisting($connection, $locales);
            foreach (array_chunk($termRows, $insertBatchSize) as $chunk) {
                $connection->table($this->policy->termsTable)->insert($chunk);
            }

            $deleteRows = [];
            $inserted = 0;
            $termQuery = $connection->table($this->policy->termsTable)->orderBy('id');
            if ($locales !== []) {
                $termQuery->whereIn('locale', $locales);
            }
            $termQuery->chunkById($this->policy->buildChunkSize, function ($rows) use ($connection, $insertBatchSize, &$deleteRows, &$inserted): void {
                foreach ($rows as $row) {
                    $maximumDistance = $this->policy->editDistanceFor((string) $row->normalized_term);
                    if ($maximumDistance === 0) {
                        continue;
                    }
                    foreach ($this->deletes->generate(
                        (string) $row->normalized_term,
                        $maximumDistance,
                        $this->policy->maximumDeletesPerDictionaryTerm,
                    ) as $deleteKey => $distance) {
                        $deleteRows[] = [
                            'term_id' => $row->id,
                            'locale' => $row->locale,
                            'delete_key' => $deleteKey,
                            'distance' => $distance,
                        ];
                        if (count($deleteRows) >= $insertBatchSize) {
                            $connection->table($this->policy->deletesTable)->insert($deleteRows);
                            $inserted += count($deleteRows);
                            $deleteRows = [];
                        }
                    }
                }
            });
            if ($deleteRows !== []) {
                $connection->table($this->policy->deletesTable)->insert($deleteRows);
                $inserted += count($deleteRows);
            }

            return $inserted;
        }, 3);

        return new SpellingDictionaryBuildResult($documents, count($termRows), $deleteCount, $localeTermCounts);
    }

    /** @param  list<string>  $locales */
    private function deleteExisting(Connection $connection, array $locales): void
    {
        if ($locales === []) {
            $connection->table($this->policy->deletesTable)->delete();
            $connection->table($this->policy->termsTable)->delete();

            return;
        }

        $termIds = array_map(
            static fn (mixed $id): int => (int) $id,
            $connection->table($this->policy->termsTable)->whereIn('locale', $locales)->pluck('id')->all(),
        );
        /** @var positive-int $insertBatchSize */
        $insertBatchSize = max(1, $this->policy->insertBatchSize);

        foreach (array_chunk($termIds, $insertBatchSize) as $ids) {
            $connection->table($this->policy->deletesTable)->whereIn('term_id', $ids)->delete();
        }
        $connection->table($this->policy->termsTable)->whereIn('locale', $locales)->delete();
    }

    /**
     * @return array{
     *     document_frequency: int,
     *     title_frequency: int,
     *     keyword_frequency: int,
     *     excerpt_frequency: int,
     *     content_frequency: int
     * }
     */
    private function emptyFrequencyCounts(): array
    {
        return [
            'document_frequency' => 0,
            'title_frequency' => 0,
            'keyword_frequency' => 0,
            'excerpt_frequency' => 0,
            'content_frequency' => 0,
        ];
    }

    /** @return array<string, true> */
    private function normalizedProtectedTerms(string $locale): array
    {
        $normalized = [];
        foreach ($this->policy->protectedTermsFor($locale) as $term) {
            $prepared = $this->pipeline->prepare($term, $locale);
            foreach ($prepared->tokens as $token) {
                $normalized[$token] = true;
            }
        }

        return $normalized;
    }

    private function eligible(string $token): bool
    {
        return $this->policy->editDistanceFor($token) > 0;
    }

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    private function validatedLocales(array $locales): array
    {
        $validated = [];
        foreach ($locales as $locale) {
            $locale = trim($locale);
            if ($locale === '') {
                throw new RuntimeException('Spelling dictionary locale filters must be non-empty strings.');
            }
            $validated[] = $locale;
        }
        $validated = array_values(array_unique($validated));
        usort($validated, strcmp(...));

        return $validated;
    }

    private function connection(): Connection
    {
        return $this->database->connection($this->policy->connection ?? config('persian-search.index.connection'));
    }
}
