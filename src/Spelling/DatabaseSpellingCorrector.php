<?php

namespace Zarbinco\PersianSearch\Spelling;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Zarbinco\PersianSearch\Contracts\SpellingCorrector;
use Zarbinco\PersianSearch\Exceptions\SpellingDictionaryUnavailableException;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final class DatabaseSpellingCorrector implements SpellingCorrector
{
    /** @var array<string, array<string, true>> */
    private array $protected = [];

    public function __construct(
        private readonly SpellingPolicy $policy,
        private readonly SymmetricDeleteGenerator $deletes,
        private readonly WeightedDamerauLevenshtein $distance,
        private readonly DatabaseManager $database,
        private readonly SearchTextPipeline $pipeline,
    ) {}

    public function correct(QueryVariant $variant): SpellingCorrectionCollection
    {
        $corrections = new SpellingCorrectionCollection($this->policy->maximumQueryVariants);
        if (! $this->policy->enabled) {
            return $corrections;
        }

        try {
            return $this->correctWithDictionary($variant, $corrections);
        } catch (QueryException $exception) {
            if (! $this->isMissingDictionaryTable($exception)) {
                throw $exception;
            }

            if ($this->policy->failWhenUnavailable) {
                throw new SpellingDictionaryUnavailableException;
            }

            return $corrections;
        }
    }

    private function correctWithDictionary(
        QueryVariant $variant,
        SpellingCorrectionCollection $corrections,
    ): SpellingCorrectionCollection {
        $tokens = $this->tokens($variant->query);
        if ($tokens === []) {
            return $corrections;
        }

        $locales = $this->policy->localeChain($variant->locale);
        $exact = $this->exactTerms($tokens, $locales);
        $requests = [];

        foreach ($tokens as $index => $token) {
            if (count($requests) >= $this->policy->maximumTokensToInspect) {
                break;
            }

            $maximumDistance = $this->policy->editDistanceFor($token);
            if ($maximumDistance === 0 || isset($exact[$token]) || $this->isProtected($token, $variant->locale)) {
                continue;
            }

            $deleteKeys = array_keys($this->deletes->generate(
                $token,
                $maximumDistance,
                $this->policy->maximumDeleteKeysPerQueryToken,
            ));
            if ($deleteKeys === []) {
                continue;
            }

            $requests[$index] = [
                'token' => $token,
                'maximum_distance' => $maximumDistance,
                'delete_keys' => $deleteKeys,
            ];
        }

        if ($requests === []) {
            return $corrections;
        }

        $optionsByIndex = $this->candidateOptions($requests, $locales);
        $correctableIndexes = array_keys(array_filter(
            $optionsByIndex,
            static fn (array $options): bool => $options !== [],
        ));

        if ($correctableIndexes === []) {
            return $corrections;
        }

        $states = [[
            'tokens' => $tokens,
            'corrections' => [],
            'cost' => 0,
            'frequency' => 0,
            'unresolved' => count($correctableIndexes),
        ]];

        foreach ($correctableIndexes as $index) {
            $expanded = [];
            foreach ($states as $state) {
                $expanded[] = $state;
                if (count($state['corrections']) >= $this->policy->maximumTokensToCorrect) {
                    continue;
                }

                foreach ($optionsByIndex[$index] as $candidate) {
                    $next = $state;
                    $next['tokens'][$index] = $candidate->term;
                    $next['corrections'][] = new SpellingTokenCorrection(
                        tokenIndex: $index,
                        original: $tokens[$index],
                        corrected: $candidate->term,
                        editDistance: $candidate->editDistance,
                        weightedCost: $candidate->weightedCost,
                        documentFrequency: $candidate->documentFrequency,
                    );
                    $next['cost'] += $candidate->weightedCost;
                    $next['frequency'] += $candidate->documentFrequency;
                    $next['unresolved']--;
                    $expanded[] = $next;
                }
            }

            usort($expanded, static function (array $left, array $right): int {
                return $left['unresolved'] <=> $right['unresolved']
                    ?: $left['cost'] <=> $right['cost']
                    ?: $right['frequency'] <=> $left['frequency']
                    ?: strcmp(implode("\0", $left['tokens']), implode("\0", $right['tokens']));
            });
            $states = array_slice(
                $this->deduplicateStates($expanded),
                0,
                max(20, $this->policy->maximumQueryVariants * 4),
            );
        }

        foreach ($states as $state) {
            if ($state['corrections'] === []) {
                continue;
            }

            $correctedQuery = implode(' ', $state['tokens']);
            $correctionLocale = $this->correctionLocale($optionsByIndex, $state['corrections'], $variant->locale);
            $fingerprint = hash('sha256', implode("\0", [
                'spelling',
                $variant->fingerprint,
                $variant->query,
                $correctedQuery,
                $correctionLocale,
            ]));
            $corrections->add(new SpellingCorrection(
                originalQuery: $variant->query,
                correctedQuery: $correctedQuery,
                locale: $correctionLocale,
                tokens: $state['tokens'],
                corrections: $state['corrections'],
                weightedCost: $state['cost'],
                fingerprint: $fingerprint,
            ));
        }

        return $corrections;
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $locales
     * @return array<string, true>
     */
    private function exactTerms(array $tokens, array $locales): array
    {
        $rows = $this->connection()->table($this->policy->termsTable)
            ->whereIn('locale', $locales)
            ->whereIn('normalized_term', array_values(array_unique($tokens)))
            ->pluck('normalized_term')
            ->all();

        return array_fill_keys(array_map('strval', $rows), true);
    }

    /**
     * @param  array<int, array{token: string, maximum_distance: int, delete_keys: list<string>}>  $requests
     * @param  list<string>  $locales
     * @return array<int, list<SpellingTokenCandidate>>
     */
    private function candidateOptions(array $requests, array $locales): array
    {
        /** @var array<string, list<int>> $indexesByDeleteKey */
        $indexesByDeleteKey = [];

        foreach ($requests as $index => $request) {
            foreach ($request['delete_keys'] as $deleteKey) {
                if (! isset($indexesByDeleteKey[$deleteKey])
                    && count($indexesByDeleteKey) >= $this->policy->maximumDeleteKeysPerQuery) {
                    break 2;
                }

                $indexesByDeleteKey[$deleteKey] ??= [];
                if (! in_array($index, $indexesByDeleteKey[$deleteKey], true)) {
                    $indexesByDeleteKey[$deleteKey][] = $index;
                }
            }
        }

        if ($indexesByDeleteKey === []) {
            return [];
        }

        /** @var Collection<int, \stdClass> $rows */
        $rows = $this->connection()->table($this->policy->deletesTable.' as spelling_delete')
            ->join($this->policy->termsTable.' as spelling_term', 'spelling_term.id', '=', 'spelling_delete.term_id')
            ->whereIn('spelling_delete.locale', $locales)
            ->whereIn('spelling_delete.delete_key', array_keys($indexesByDeleteKey))
            ->select([
                'spelling_delete.delete_key',
                'spelling_term.normalized_term',
                'spelling_term.locale',
                'spelling_term.document_frequency',
                'spelling_term.title_frequency',
            ])
            ->orderByDesc('spelling_term.document_frequency')
            ->orderByDesc('spelling_term.title_frequency')
            ->orderBy('spelling_term.normalized_term')
            ->orderBy('spelling_delete.delete_key')
            ->limit($this->policy->maximumCandidateRowsPerQuery)
            ->get();

        /** @var array<int, array<string, \stdClass>> $candidateRowsByIndex */
        $candidateRowsByIndex = [];
        foreach ($rows as $row) {
            $deleteKey = (string) $row->delete_key;
            foreach ($indexesByDeleteKey[$deleteKey] ?? [] as $index) {
                $identity = (string) $row->locale."\0".(string) $row->normalized_term;
                if (isset($candidateRowsByIndex[$index][$identity])) {
                    continue;
                }
                if (count($candidateRowsByIndex[$index] ?? []) >= $this->policy->maximumCandidateRowsPerToken) {
                    continue;
                }

                $candidateRowsByIndex[$index][$identity] = $row;
            }
        }

        $options = [];
        foreach ($requests as $index => $request) {
            $candidates = [];
            foreach ($candidateRowsByIndex[$index] ?? [] as $row) {
                $term = (string) $row->normalized_term;
                if ($term === $request['token']) {
                    continue;
                }

                $measurement = $this->distance->measure($request['token'], $term, (string) $row->locale);
                if ($measurement->edits < 1 || $measurement->edits > $request['maximum_distance']) {
                    continue;
                }

                $candidates[] = new SpellingTokenCandidate(
                    term: $term,
                    locale: (string) $row->locale,
                    editDistance: $measurement->edits,
                    weightedCost: $measurement->cost,
                    documentFrequency: (int) $row->document_frequency,
                    titleFrequency: (int) $row->title_frequency,
                );
            }

            usort($candidates, static function (SpellingTokenCandidate $left, SpellingTokenCandidate $right) use ($locales): int {
                $leftLocale = array_search($left->locale, $locales, true);
                $rightLocale = array_search($right->locale, $locales, true);

                return $left->weightedCost <=> $right->weightedCost
                    ?: $left->editDistance <=> $right->editDistance
                    ?: ($leftLocale === false ? PHP_INT_MAX : $leftLocale) <=> ($rightLocale === false ? PHP_INT_MAX : $rightLocale)
                    ?: $right->documentFrequency <=> $left->documentFrequency
                    ?: $right->titleFrequency <=> $left->titleFrequency
                    ?: strcmp($left->term, $right->term);
            });

            $options[$index] = array_slice($candidates, 0, $this->policy->maximumCandidatesPerToken);
        }

        return $options;
    }

    /**
     * @param  list<array<string, mixed>>  $states
     * @return list<array<string, mixed>>
     */
    private function deduplicateStates(array $states): array
    {
        $seen = [];
        $unique = [];
        foreach ($states as $state) {
            $key = implode("\0", $state['tokens']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $state;
        }

        return $unique;
    }

    /**
     * @param  array<int, list<SpellingTokenCandidate>>  $optionsByIndex
     * @param  list<SpellingTokenCorrection>  $corrections
     */
    private function correctionLocale(array $optionsByIndex, array $corrections, string $fallback): string
    {
        foreach ($corrections as $correction) {
            foreach ($optionsByIndex[$correction->tokenIndex] ?? [] as $candidate) {
                if ($candidate->term === $correction->corrected) {
                    return $candidate->locale;
                }
            }
        }

        return $fallback;
    }

    /** @return list<string> */
    private function tokens(string $query): array
    {
        preg_match_all(
            "/[\p{L}\p{N}][\p{L}\p{M}\p{N}]*(?:['’][\p{L}\p{N}][\p{L}\p{M}\p{N}]*)*/u",
            $query,
            $matches,
        );

        return array_values(array_filter(
            $matches[0],
            static fn (string $token): bool => $token !== '',
        ));
    }

    private function isProtected(string $token, string $locale): bool
    {
        if (! isset($this->protected[$locale])) {
            $terms = [];
            foreach ($this->policy->protectedTermsFor($locale) as $protected) {
                foreach ($this->pipeline->prepare($protected, $locale)->tokens as $normalized) {
                    $terms[$normalized] = true;
                }
            }
            $this->protected[$locale] = $terms;
        }

        return isset($this->protected[$locale][$token]);
    }

    private function isMissingDictionaryTable(QueryException $exception): bool
    {
        $sql = $exception->getSql();
        if (! str_contains($sql, $this->policy->termsTable)
            && ! str_contains($sql, $this->policy->deletesTable)) {
            return false;
        }

        $errorInfo = $exception->errorInfo;
        $sqlState = is_array($errorInfo) && isset($errorInfo[0])
            ? (string) $errorInfo[0]
            : (string) $exception->getCode();

        if (in_array($sqlState, ['42P01', '42S02'], true)) {
            return true;
        }

        $driverCode = is_array($errorInfo) && isset($errorInfo[1])
            ? (int) $errorInfo[1]
            : 0;

        return $sqlState === 'HY000'
            && $driverCode === 1
            && str_contains(strtolower($exception->getMessage()), 'no such table');
    }

    private function connection(): Connection
    {
        return $this->database->connection($this->policy->connection ?? config('persian-search.index.connection'));
    }
}
