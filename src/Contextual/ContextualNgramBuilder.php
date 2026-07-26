<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Spelling\SpellingPolicy;

final readonly class ContextualNgramBuilder
{
    public function __construct(
        private ContextualCorrectionPolicy $policy,
        private SpellingPolicy $spelling,
        private SearchTokenizer $tokenizer,
        private DatabaseManager $database,
    ) {}

    public function shouldBuild(): bool
    {
        return $this->policy->ngramsEnabled && $this->policy->buildNgrams;
    }

    /** @param list<string> $locales */
    public function markDictionaryRebuilt(array $locales = []): void
    {
        $locales = $this->dictionaryLocales($this->validatedLocales($locales));
        if ($locales === []) {
            return;
        }
        $connection = $this->connection();
        if (! $connection->getSchemaBuilder()->hasTable($this->policy->buildsTable)) {
            if (! $this->policy->enabled) {
                return;
            }

            throw new RuntimeException('Persian search contextual build metadata table does not exist. Run the package migrations first.');
        }

        $this->writeDictionaryGenerations($connection, $locales);
    }

    /** @param list<string> $locales */
    public function rebuild(array $locales = []): ContextualNgramBuildResult
    {
        if (! $this->shouldBuild()) {
            return new ContextualNgramBuildResult(0, 0, []);
        }

        $connection = $this->connection();
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable($this->policy->ngramsTable)
            || ! $schema->hasTable($this->policy->ngramStagingTable)
            || ! $schema->hasTable($this->policy->buildsTable)) {
            if (! $this->policy->enabled) {
                return new ContextualNgramBuildResult(0, 0, []);
            }

            throw new RuntimeException('Persian search contextual n-gram tables do not exist. Run the package migrations first.');
        }

        $locales = $this->validatedLocales($locales);
        $buildLocales = $this->dictionaryLocales($locales);
        $existingGenerations = $connection->table($this->policy->buildsTable)
            ->whereIn('locale', $buildLocales)
            ->pluck('dictionary_generation', 'locale')
            ->mapWithKeys(static fn (mixed $generation, mixed $locale): array => [
                (string) $locale => (string) $generation,
            ])
            ->all();
        $missingLocales = array_values(array_filter(
            $buildLocales,
            static fn (string $locale): bool => ! isset($existingGenerations[$locale]),
        ));
        if ($missingLocales !== []) {
            $this->writeDictionaryGenerations($connection, $missingLocales);
            $existingGenerations = $connection->table($this->policy->buildsTable)
                ->whereIn('locale', $buildLocales)
                ->pluck('dictionary_generation', 'locale')
                ->mapWithKeys(static fn (mixed $generation, mixed $locale): array => [
                    (string) $locale => (string) $generation,
                ])
                ->all();
        }
        $buildToken = (string) Str::uuid();
        $documents = 0;
        $rows = [];
        $now = Carbon::now();
        $insertBatchSize = max(1, $this->policy->insertBatchSize);

        try {
            $query = SearchDocumentRecord::query()->where('is_active', true)->orderBy('id');
            if ($locales !== []) {
                $query->whereIn('locale', $locales);
            }

            $query->chunkById($this->spelling->buildChunkSize, function ($records) use (
                $connection,
                $buildToken,
                $now,
                $insertBatchSize,
                &$documents,
                &$rows,
            ): void {
                foreach ($records as $record) {
                    $locale = (string) $record->locale;
                    $documentGrams = [];
                    $generated = 0;
                    foreach ([
                        'title' => $record->normalized_title,
                        'keywords' => $record->normalized_keywords,
                        'excerpt' => $record->normalized_excerpt,
                        'content' => $record->normalized_content,
                    ] as $field => $value) {
                        if (! is_string($value) || $value === '') {
                            continue;
                        }
                        $tokens = array_slice(
                            $this->tokenizer->tokenize($value, $locale),
                            0,
                            $this->policy->maximumTermsPerDocument,
                        );
                        for ($index = 0; $index < count($tokens) - 1; $index++) {
                            if ($generated >= $this->policy->maximumNgramsPerDocument) {
                                break 2;
                            }
                            $first = $tokens[$index];
                            $second = $tokens[$index + 1];
                            if (! $this->safeTerm($first) || ! $this->safeTerm($second)) {
                                continue;
                            }
                            $generated++;
                            $gram = $first.' '.$second;
                            $identity = hash('sha256', $gram);
                            $entry = $documentGrams[$identity] ?? [
                                'build_token' => $buildToken,
                                'locale' => $locale,
                                'gram_size' => 2,
                                'gram_hash' => $identity,
                                'normalized_gram' => $gram,
                                'first_term' => $first,
                                'second_term' => $second,
                                'document_frequency' => 1,
                                'title_frequency' => 0,
                                'keyword_frequency' => 0,
                                'created_at' => $now,
                            ];
                            if ($field === 'title') {
                                $entry['title_frequency']++;
                            } elseif ($field === 'keywords') {
                                $entry['keyword_frequency']++;
                            }
                            $documentGrams[$identity] = $entry;
                        }
                    }

                    array_push($rows, ...array_values($documentGrams));
                    if (count($rows) >= $insertBatchSize) {
                        foreach (array_chunk($rows, $insertBatchSize) as $batch) {
                            $connection->table($this->policy->ngramStagingTable)->insert($batch);
                        }
                        $rows = [];
                    }
                    $documents++;
                }
            });

            foreach (array_chunk($rows, $insertBatchSize) as $batch) {
                $connection->table($this->policy->ngramStagingTable)->insert($batch);
            }

            $localeCounts = $connection->transaction(function () use (
                $connection,
                $buildToken,
                $locales,
                $buildLocales,
                $existingGenerations,
                $now,
            ): array {
                $target = $connection->table($this->policy->ngramsTable);
                if ($locales === []) {
                    $target->delete();
                } else {
                    $target->whereIn('locale', $locales)->delete();
                }

                $aggregate = $connection->table($this->policy->ngramStagingTable)
                    ->where('build_token', $buildToken)
                    ->select([
                        'locale',
                        'gram_size',
                        'gram_hash',
                        'normalized_gram',
                        'first_term',
                        'second_term',
                    ])
                    ->selectRaw('SUM(document_frequency) as document_frequency')
                    ->selectRaw('SUM(title_frequency) as title_frequency')
                    ->selectRaw('SUM(keyword_frequency) as keyword_frequency')
                    ->selectRaw('? as indexed_at', [$now])
                    ->selectRaw('? as created_at', [$now])
                    ->selectRaw('? as updated_at', [$now])
                    ->groupBy([
                        'locale',
                        'gram_size',
                        'gram_hash',
                        'normalized_gram',
                        'first_term',
                        'second_term',
                    ])
                    ->havingRaw('SUM(document_frequency) >= ?', [$this->policy->minimumNgramDocumentFrequency]);

                $connection->table($this->policy->ngramsTable)->insertUsing([
                    'locale',
                    'gram_size',
                    'gram_hash',
                    'normalized_gram',
                    'first_term',
                    'second_term',
                    'document_frequency',
                    'title_frequency',
                    'keyword_frequency',
                    'indexed_at',
                    'created_at',
                    'updated_at',
                ], $aggregate);

                $localeCounts = [];
                $counts = $connection->table($this->policy->ngramsTable)
                    ->select('locale')
                    ->selectRaw('COUNT(*) as aggregate');
                if ($locales !== []) {
                    $counts->whereIn('locale', $locales);
                }
                foreach ($counts->groupBy('locale')->orderBy('locale')->get() as $row) {
                    $localeCounts[(string) $row->locale] = (int) $row->aggregate;
                }
                foreach ($buildLocales as $locale) {
                    $generation = $existingGenerations[$locale] ?? null;
                    if ($generation === null) {
                        continue;
                    }
                    $connection->table($this->policy->buildsTable)
                        ->where('locale', $locale)
                        ->where('dictionary_generation', $generation)
                        ->update([
                            'ngram_generation' => $generation,
                            'ngram_count' => $localeCounts[$locale] ?? 0,
                            'ngram_indexed_at' => $now,
                            'updated_at' => $now,
                        ]);
                }
                if ($locales === []) {
                    $obsoleteBuilds = $connection->table($this->policy->buildsTable);
                    if ($buildLocales === []) {
                        $obsoleteBuilds->delete();
                    } else {
                        $obsoleteBuilds->whereNotIn('locale', $buildLocales)->delete();
                    }
                }

                return $localeCounts;
            }, 3);

            return new ContextualNgramBuildResult($documents, array_sum($localeCounts), $localeCounts);
        } finally {
            $connection->table($this->policy->ngramStagingTable)
                ->where('build_token', $buildToken)
                ->delete();
        }
    }

    /**
     * @param  array<int, mixed>  $locales
     * @return list<string>
     */
    private function validatedLocales(array $locales): array
    {
        $validated = [];
        foreach ($locales as $locale) {
            if (! is_string($locale) || trim($locale) === '') {
                throw new RuntimeException('Contextual n-gram locale filters must be non-empty strings.');
            }
            $validated[] = trim($locale);
        }
        $validated = array_values(array_unique($validated));
        usort($validated, strcmp(...));

        return $validated;
    }

    private function safeTerm(string $term): bool
    {
        return $term !== '' && strlen($term) <= 764
            && preg_match('/\A[\p{L}\p{M}]+(?:[\'’][\p{L}\p{M}]+)*\z/uD', $term) === 1;
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function dictionaryLocales(array $requested): array
    {
        if ($requested !== []) {
            return $requested;
        }

        return array_values(array_map(
            'strval',
            $this->spellingConnection()->table($this->spelling->termsTable)
                ->distinct()
                ->orderBy('locale')
                ->pluck('locale')
                ->all(),
        ));
    }

    /** @param list<string> $locales */
    private function writeDictionaryGenerations(Connection $connection, array $locales): void
    {
        $now = Carbon::now();
        $termCounts = $this->spellingConnection()->table($this->spelling->termsTable)
            ->whereIn('locale', $locales)
            ->select('locale')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('locale')
            ->pluck('aggregate', 'locale');
        $document = new SearchDocumentRecord;
        $documentCounts = $document->getConnection()->table($document->getTable())
            ->where('is_active', true)
            ->whereIn('locale', $locales)
            ->select('locale')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('locale')
            ->pluck('aggregate', 'locale');
        $rows = [];
        foreach ($locales as $locale) {
            $rows[] = [
                'locale' => $locale,
                'dictionary_generation' => (string) Str::uuid(),
                'ngram_generation' => null,
                'term_count' => (int) ($termCounts[$locale] ?? 0),
                'document_count' => (int) ($documentCounts[$locale] ?? 0),
                'ngram_count' => 0,
                'dictionary_indexed_at' => $now,
                'ngram_indexed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $connection->table($this->policy->buildsTable)->upsert(
            $rows,
            ['locale'],
            [
                'dictionary_generation',
                'ngram_generation',
                'term_count',
                'document_count',
                'ngram_count',
                'dictionary_indexed_at',
                'ngram_indexed_at',
                'updated_at',
            ],
        );
    }

    private function connection(): Connection
    {
        return $this->database->connection(
            $this->policy->connection
                ?? $this->spelling->connection
                ?? config('persian-search.index.connection'),
        );
    }

    private function spellingConnection(): Connection
    {
        return $this->database->connection(
            $this->spelling->connection
                ?? config('persian-search.index.connection'),
        );
    }
}
