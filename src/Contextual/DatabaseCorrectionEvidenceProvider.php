<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Zarbinco\PersianSearch\Contracts\CorrectionEvidenceProvider;
use Zarbinco\PersianSearch\Contracts\QueryClickSignalProvider;
use Zarbinco\PersianSearch\Contracts\QueryPopularityProvider;
use Zarbinco\PersianSearch\Spelling\SpellingPolicy;

final readonly class DatabaseCorrectionEvidenceProvider implements CorrectionEvidenceProvider
{
    public function __construct(
        private ContextualCorrectionPolicy $policy,
        private SpellingPolicy $spelling,
        private DatabaseManager $database,
        private QueryPopularityProvider $popularity,
        private QueryClickSignalProvider $clicks,
    ) {}

    public function evidenceFor(ContextualCandidateCollection $candidates): CorrectionEvidenceCollection
    {
        if ($candidates->isEmpty()) {
            return new CorrectionEvidenceCollection;
        }

        /** @var array<string, array{original: list<string>, candidate: list<string>}> $grams */
        $grams = [];
        /** @var array<string, true> $hashes */
        $hashes = [];
        /** @var array<string, true> $locales */
        $locales = [];
        foreach ($candidates as $candidate) {
            $candidateGrams = $this->candidateGrams($candidate);
            $grams[$candidate->fingerprint] = $candidateGrams;
            $locales[$candidate->locale] = true;
            foreach ([...$candidateGrams['original'], ...$candidateGrams['candidate']] as $gram) {
                if (count($hashes) >= $this->policy->maximumContextLookups && ! isset($hashes[hash('sha256', $gram)])) {
                    continue;
                }
                $hashes[hash('sha256', $gram)] = true;
            }
        }

        $rows = [];
        $readyLocales = [];
        if ($this->policy->ngramsEnabled && $hashes !== []) {
            $readyLocales = $this->freshLocales(array_keys($locales));
            try {
                foreach ($this->connection()->table($this->policy->ngramsTable)
                    ->whereIn('locale', array_keys($readyLocales))
                    ->where('gram_size', 2)
                    ->whereIn('gram_hash', array_keys($hashes))
                    ->select([
                        'locale',
                        'gram_hash',
                        'normalized_gram',
                        'document_frequency',
                        'title_frequency',
                        'keyword_frequency',
                    ])
                    ->orderBy('locale')
                    ->orderBy('gram_hash')
                    ->limit($this->policy->maximumContextLookups * max(1, count($locales)))
                    ->get() as $row) {
                    $rows[(string) $row->locale][(string) $row->normalized_gram] = $row;
                }
            } catch (QueryException $exception) {
                if (! $this->isMissingConfiguredTable($exception, $this->policy->ngramsTable)) {
                    throw $exception;
                }
                $readyLocales = [];
            }
        }

        $evidence = [];
        foreach ($candidates as $candidate) {
            $candidateGrams = $grams[$candidate->fingerprint];
            $originalContext = $this->contextScore($rows, $candidate->locale, $candidateGrams['original']);
            $correctedContext = $this->contextScore($rows, $candidate->locale, $candidateGrams['candidate']);
            $ready = isset($readyLocales[$candidate->locale]);
            $evidence[] = new CorrectionEvidence(
                candidateFingerprint: $candidate->fingerprint,
                originalUnigramScore: $candidate->originalCorpusScore,
                candidateUnigramScore: $candidate->candidateCorpusScore,
                originalContextScore: $originalContext,
                candidateContextScore: $correctedContext,
                originalPhraseFrequency: count($candidate->parent->tokens) === 2
                    ? $this->phraseFrequency($rows, $candidate->locale, $candidateGrams['original'])
                    : 0,
                candidatePhraseFrequency: count($candidate->tokens) === 2
                    ? $this->phraseFrequency($rows, $candidate->locale, $candidateGrams['candidate'])
                    : 0,
                contextApplicable: $candidateGrams['original'] !== [] || $candidateGrams['candidate'] !== [],
                ngramsReady: $ready,
                popularitySignal: $this->popularity->popularity($candidate->correctedQuery, $candidate->locale),
                clickSignal: $this->clicks->clickConfidence($candidate->correctedQuery, $candidate->locale),
                contextAvailable: $this->policy->ngramsEnabled && $ready,
            );
        }

        return new CorrectionEvidenceCollection($evidence);
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, true>
     */
    private function freshLocales(array $locales): array
    {
        if ($locales === []) {
            return [];
        }

        try {
            $ready = [];
            foreach ($this->connection()->table($this->policy->buildsTable)
                ->whereIn('locale', $locales)
                ->select([
                    'locale',
                    'dictionary_generation',
                    'ngram_generation',
                    'ngram_indexed_at',
                ])
                ->get() as $row) {
                if ((string) $row->dictionary_generation !== ''
                    && $row->ngram_generation !== null
                    && (string) $row->dictionary_generation === (string) $row->ngram_generation
                    && $row->ngram_indexed_at !== null) {
                    $ready[(string) $row->locale] = true;
                }
            }
        } catch (QueryException $exception) {
            if ($this->isMissingConfiguredTable($exception, $this->policy->buildsTable)) {
                return [];
            }
            throw $exception;
        }

        return $ready;
    }

    /** @return array{original: list<string>, candidate: list<string>} */
    private function candidateGrams(ContextualCandidate $candidate): array
    {
        $indexes = array_fill_keys($candidate->correctedTokenIndexes(), true);
        $original = [];
        $corrected = [];
        for ($index = 0; $index < count($candidate->tokens) - 1; $index++) {
            if (! isset($indexes[$index]) && ! isset($indexes[$index + 1])) {
                continue;
            }
            $original[] = $candidate->parent->tokens[$index].' '.$candidate->parent->tokens[$index + 1];
            $corrected[] = $candidate->tokens[$index].' '.$candidate->tokens[$index + 1];
        }

        return [
            'original' => array_values(array_unique($original)),
            'candidate' => array_values(array_unique($corrected)),
        ];
    }

    /**
     * @param  array<string, array<string, \stdClass>>  $rows
     * @param  list<string>  $grams
     */
    private function contextScore(array $rows, string $locale, array $grams): int
    {
        $score = 0;
        foreach ($grams as $gram) {
            $row = $rows[$locale][$gram] ?? null;
            if ($row === null) {
                continue;
            }
            $score += ((int) $row->document_frequency * 100)
                + ((int) $row->title_frequency * 10)
                + (int) $row->keyword_frequency;
        }

        return $score;
    }

    /**
     * @param  array<string, array<string, \stdClass>>  $rows
     * @param  list<string>  $grams
     */
    private function phraseFrequency(array $rows, string $locale, array $grams): int
    {
        $frequency = 0;
        foreach ($grams as $gram) {
            $row = $rows[$locale][$gram] ?? null;
            if ($row !== null) {
                $frequency += (int) $row->document_frequency;
            }
        }

        return $frequency;
    }

    private function isMissingConfiguredTable(QueryException $exception, string $table): bool
    {
        if (preg_match(
            '/(?<![A-Za-z0-9_])'.preg_quote($table, '/').'(?![A-Za-z0-9_])/i',
            $exception->getSql(),
        ) !== 1) {
            return false;
        }
        $errorInfo = $exception->errorInfo;
        $state = is_array($errorInfo) && isset($errorInfo[0])
            ? (string) $errorInfo[0]
            : (string) $exception->getCode();
        $driver = is_array($errorInfo) && isset($errorInfo[1]) ? (int) $errorInfo[1] : 0;

        return in_array($state, ['42P01', '42S02'], true)
            || ($state === 'HY000' && $driver === 1
                && str_contains(strtolower($exception->getMessage()), 'no such table'));
    }

    private function connection(): Connection
    {
        return $this->database->connection(
            $this->policy->connection
                ?? $this->spelling->connection
                ?? config('persian-search.index.connection'),
        );
    }
}
