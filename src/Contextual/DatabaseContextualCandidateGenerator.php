<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Zarbinco\PersianSearch\Correction\AdvancedCorrectionPolicy;
use Zarbinco\PersianSearch\Correction\LanguageCorrectionProfileRegistry;
use Zarbinco\PersianSearch\Exceptions\SpellingDictionaryUnavailableException;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;
use Zarbinco\PersianSearch\Spelling\SpellingPolicy;
use Zarbinco\PersianSearch\Spelling\SymmetricDeleteGenerator;
use Zarbinco\PersianSearch\Spelling\WeightedDamerauLevenshtein;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final class DatabaseContextualCandidateGenerator
{
    /** @var array<string, array<string, true>> */
    private array $protected = [];

    public function __construct(
        private readonly ContextualCorrectionPolicy $policy,
        private readonly SpellingPolicy $spelling,
        private readonly SymmetricDeleteGenerator $deletes,
        private readonly WeightedDamerauLevenshtein $distance,
        private readonly LanguageCorrectionProfileRegistry $profiles,
        private readonly DatabaseManager $database,
        private readonly SearchTextPipeline $pipeline,
        private readonly ?AdvancedCorrectionPolicy $advanced = null,
    ) {}

    public function generate(QueryVariantCollection $variants): ContextualCandidateCollection
    {
        $result = new ContextualCandidateCollection($this->policy->maximumCandidatesPerQuery);
        if (! $this->policy->enabled) {
            return $result;
        }

        /** @var list<QueryVariant> $eligibleParents */
        $eligibleParents = [];
        /**
         * @var array<string, array{
         *   parent: QueryVariant,
         *   index: int,
         *   token: string,
         *   locales: list<string>,
         *   delete_keys: list<string>,
         *   phonetic: array<string, array{cost: int, rule: string}>
         * }> $requests
         */
        $requests = [];
        /** @var array<string, list<string>> $requestsByDelete */
        $requestsByDelete = [];
        /** @var array<string, true> $lookupTerms */
        $lookupTerms = [];

        foreach ($variants as $parent) {
            if (! $parent->source->isContextualParent()
                || $this->unsafeQuery($parent->query)
                || count($parent->tokens) > $this->policy->maximumQueryTokens
                || $this->length($parent->query) > $this->policy->maximumQueryLength) {
                continue;
            }
            $advancedCorrection = $parent->advancedCorrection;
            $contextualCorrection = $parent->contextualCorrection;
            $priorDepth = ($advancedCorrection === null ? 0 : $advancedCorrection->transformationDepth)
                + ($contextualCorrection === null ? 0 : count($contextualCorrection->candidate->corrections));
            if ($priorDepth >= $this->policy->maximumTransformationDepth) {
                continue;
            }
            $eligibleParents[] = $parent;

            $inspected = 0;
            foreach ($parent->tokens as $index => $token) {
                if ($inspected >= $this->policy->maximumTokensToInspect) {
                    break;
                }
                if (! $this->safeWord($token) || $this->spelling->editDistanceFor($token) === 0
                    || $this->isProtected($token, $parent->locale)) {
                    continue;
                }
                $inspected++;
                $maximumDistance = $this->spelling->editDistanceFor($token);
                $deleteKeys = array_keys($this->deletes->generate(
                    $token,
                    $maximumDistance,
                    min($this->policy->maximumDeleteKeys, $this->spelling->maximumDeleteKeysPerQueryToken),
                ));
                $identity = $parent->fingerprint."\0".$index;
                $phonetic = [];
                $profile = $this->profiles->forLocale($parent->locale);
                if ($profile !== null) {
                    $generated = 0;
                    foreach ($profile->phoneticAlternatives($token) as $alternative) {
                        $maximumAlternatives = $this->advanced === null
                            ? 32
                            : $this->advanced->maximumAlternativesPerToken;
                        if (++$generated > $maximumAlternatives) {
                            break;
                        }
                        if ($alternative->token === $token || ! $this->safeWord($alternative->token)) {
                            continue;
                        }
                        $phonetic[$alternative->token] = [
                            'cost' => $alternative->cost + ($this->advanced === null
                                ? 1200
                                : $this->advanced->phoneticBaseCost),
                            'rule' => $alternative->rule,
                        ];
                        $lookupTerms[$alternative->token] = true;
                    }
                }
                $lookupTerms[$token] = true;
                $requests[$identity] = [
                    'parent' => $parent,
                    'index' => $index,
                    'token' => $token,
                    'locales' => $this->spelling->localeChain($parent->locale),
                    'delete_keys' => $deleteKeys,
                    'phonetic' => $phonetic,
                ];
                foreach ($deleteKeys as $deleteKey) {
                    if (! isset($requestsByDelete[$deleteKey])
                        && count($requestsByDelete) >= $this->policy->maximumDeleteKeys) {
                        break;
                    }
                    $requestsByDelete[$deleteKey] ??= [];
                    $requestsByDelete[$deleteKey][] = $identity;
                }
            }
        }

        if ($requests === []) {
            return $result;
        }

        $allLocales = [];
        foreach ($requests as $request) {
            foreach ($request['locales'] as $locale) {
                $allLocales[$locale] = true;
            }
        }

        try {
            $termRows = $this->termRows(array_keys($lookupTerms), array_keys($allLocales));
            $deleteRows = $this->deleteRows(array_keys($requestsByDelete), array_keys($allLocales));
        } catch (QueryException $exception) {
            if (! $this->missingDictionary($exception)) {
                throw $exception;
            }
            if ($this->spelling->failWhenUnavailable) {
                throw new SpellingDictionaryUnavailableException;
            }

            return $result;
        }
        foreach ($deleteRows as $row) {
            $termRows[(string) $row->locale][(string) $row->normalized_term] = $row;
        }

        /** @var array<string, array<string, ContextualTokenOption>> $options */
        $options = [];
        foreach ($deleteRows as $row) {
            $deleteKey = (string) $row->delete_key;
            foreach ($requestsByDelete[$deleteKey] ?? [] as $identity) {
                $request = $requests[$identity];
                $this->acceptOption(
                    $options,
                    $identity,
                    $request,
                    (string) $row->normalized_term,
                    (string) $row->locale,
                    ContextualCandidateSource::Edit,
                    null,
                    $termRows,
                );
            }
        }
        foreach ($requests as $identity => $request) {
            foreach ($request['phonetic'] as $term => $metadata) {
                foreach ($request['locales'] as $locale) {
                    if (! isset($termRows[$locale][$term])) {
                        continue;
                    }
                    $this->acceptOption(
                        $options,
                        $identity,
                        $request,
                        $term,
                        $locale,
                        ContextualCandidateSource::Phonetic,
                        $metadata,
                        $termRows,
                    );
                    break;
                }
            }
        }

        /** @var array<string, array<int, list<ContextualTokenOption>>> $byParent */
        $byParent = [];
        foreach ($options as $identity => $byTerm) {
            $request = $requests[$identity];
            $values = array_values($byTerm);
            usort($values, static fn (ContextualTokenOption $left, ContextualTokenOption $right): int => $left->lexicalCost <=> $right->lexicalCost
                ?: $right->candidateCorpusScore <=> $left->candidateCorpusScore
                ?: strcmp($left->corrected, $right->corrected));
            $byParent[$request['parent']->fingerprint][$request['index']] = array_slice(
                $values,
                0,
                $this->policy->maximumCandidatesPerToken,
            );
        }

        $pool = [];
        $poolLimit = $this->policy->maximumCandidatesPerQuery * max(1, count($eligibleParents));
        foreach ($eligibleParents as $parent) {
            $optionsByIndex = $byParent[$parent->fingerprint] ?? [];
            if ($optionsByIndex === []) {
                continue;
            }
            foreach ($this->compose($parent, $optionsByIndex) as $candidate) {
                $pool[] = $candidate;
                if (count($pool) > $poolLimit) {
                    usort($pool, self::compareCandidates(...));
                    $pool = array_slice($pool, 0, $poolLimit);
                }
            }
        }
        usort($pool, self::compareCandidates(...));
        foreach (array_slice($pool, 0, $this->policy->maximumCandidatesPerQuery) as $candidate) {
            $result->add($candidate);
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, ContextualTokenOption>>  $options
     * @param array{
     *   parent: QueryVariant,
     *   index: int,
     *   token: string,
     *   locales: list<string>,
     *   delete_keys: list<string>,
     *   phonetic: array<string, array{cost: int, rule: string}>
     * } $request
     * @param  array{cost: int, rule: string}|null  $phonetic
     * @param  array<string, array<string, \stdClass>>  $termRows
     */
    private function acceptOption(
        array &$options,
        string $identity,
        array $request,
        string $term,
        string $locale,
        ContextualCandidateSource $source,
        ?array $phonetic,
        array $termRows,
    ): void {
        $original = $request['token'];
        if ($term === $original || ! $this->safeWord($term)
            || $this->isProtected($term, $request['parent']->locale)
            || ! isset($termRows[$locale][$original], $termRows[$locale][$term])) {
            return;
        }
        $measurement = $this->distance->measure($original, $term, $locale);
        $maximumDistance = $this->spelling->editDistanceFor($original);
        if ($measurement->edits < 1 || $measurement->edits > $maximumDistance) {
            return;
        }
        $originalRow = $termRows[$locale][$original];
        $candidateRow = $termRows[$locale][$term];
        $cost = $source === ContextualCandidateSource::Phonetic
            ? ($phonetic['cost'] ?? $measurement->cost)
            : $measurement->cost;
        $option = new ContextualTokenOption(
            tokenIndex: $request['index'],
            original: $original,
            corrected: $term,
            locale: $locale,
            source: $source,
            lexicalCost: $cost,
            originalDocumentFrequency: (int) $originalRow->document_frequency,
            candidateDocumentFrequency: (int) $candidateRow->document_frequency,
            originalCorpusScore: $this->corpusScore($originalRow),
            candidateCorpusScore: $this->corpusScore($candidateRow),
            rule: $source === ContextualCandidateSource::Phonetic
                ? ($phonetic['rule'] ?? 'phonetic')
                : 'weighted_damerau_levenshtein',
        );
        $existing = $options[$identity][$term] ?? null;
        if ($existing === null || $option->lexicalCost < $existing->lexicalCost
            || ($option->lexicalCost === $existing->lexicalCost
                && $option->source->value < $existing->source->value)) {
            $options[$identity][$term] = $option;
        }
    }

    /**
     * @param  array<int, list<ContextualTokenOption>>  $optionsByIndex
     * @return list<ContextualCandidate>
     */
    private function compose(QueryVariant $parent, array $optionsByIndex): array
    {
        ksort($optionsByIndex);
        $advancedCorrection = $parent->advancedCorrection;
        $maximumCorrections = min(
            $this->policy->maximumTokensToCorrect,
            $this->policy->maximumTransformationDepth
                - ($advancedCorrection === null ? 0 : $advancedCorrection->transformationDepth),
        );
        if ($maximumCorrections < 1) {
            return [];
        }
        $states = [[
            'tokens' => $parent->tokens,
            'corrections' => [],
            'cost' => 0,
            'original_corpus' => 0,
            'candidate_corpus' => 0,
            'locale' => null,
        ]];
        foreach ($optionsByIndex as $index => $tokenOptions) {
            $expanded = $states;
            foreach ($states as $state) {
                if (count($state['corrections']) >= $maximumCorrections) {
                    continue;
                }
                foreach ($tokenOptions as $option) {
                    if ($state['locale'] !== null && $state['locale'] !== $option->locale) {
                        continue;
                    }
                    $next = $state;
                    $next['tokens'][$index] = $option->corrected;
                    $next['corrections'][] = new ContextualTokenCorrection(
                        tokenIndex: $index,
                        original: $option->original,
                        corrected: $option->corrected,
                        source: $option->source,
                        lexicalCost: $option->lexicalCost,
                        originalDocumentFrequency: $option->originalDocumentFrequency,
                        candidateDocumentFrequency: $option->candidateDocumentFrequency,
                        rule: $option->rule,
                    );
                    $next['cost'] += $option->lexicalCost;
                    $next['original_corpus'] += $option->originalCorpusScore;
                    $next['candidate_corpus'] += $option->candidateCorpusScore;
                    $next['locale'] = $option->locale;
                    $expanded[] = $next;
                }
            }
            usort($expanded, static fn (array $left, array $right): int => count($right['corrections']) <=> count($left['corrections'])
                ?: $left['cost'] <=> $right['cost']
                ?: $right['candidate_corpus'] <=> $left['candidate_corpus']
                ?: strcmp(implode("\0", $left['tokens']), implode("\0", $right['tokens'])));
            $stateLimit = $this->policy->maximumCandidatesPerQuery
                * $this->policy->maximumCandidatesPerToken
                * $this->policy->maximumTokensToCorrect;
            $states = array_slice(
                $this->deduplicateStates($expanded),
                0,
                max($this->policy->maximumCandidatesPerQuery, $stateLimit),
            );
        }

        $candidates = [];
        foreach ($states as $state) {
            if ($state['corrections'] === [] || ! is_string($state['locale'])) {
                continue;
            }
            $query = implode(' ', $state['tokens']);
            $fingerprint = hash('sha256', implode("\0", [
                'contextual-candidate',
                $parent->fingerprint,
                $query,
                $state['locale'],
                (string) $state['cost'],
            ]));
            $candidates[] = new ContextualCandidate(
                originalQuery: $this->originalQuery($parent),
                parent: $parent,
                correctedQuery: $query,
                locale: $state['locale'],
                tokens: $state['tokens'],
                corrections: $state['corrections'],
                lexicalCost: $state['cost'],
                originalCorpusScore: $state['original_corpus'],
                candidateCorpusScore: $state['candidate_corpus'],
                fingerprint: $fingerprint,
            );
        }

        return $candidates;
    }

    private static function compareCandidates(ContextualCandidate $left, ContextualCandidate $right): int
    {
        $leftGain = $left->candidateCorpusScore - $left->originalCorpusScore;
        $rightGain = $right->candidateCorpusScore - $right->originalCorpusScore;

        return $rightGain <=> $leftGain
            ?: $left->lexicalCost <=> $right->lexicalCost
            ?: count($left->corrections) <=> count($right->corrections)
            ?: $right->parent->priority <=> $left->parent->priority
            ?: strcmp($left->correctedQuery, $right->correctedQuery)
            ?: strcmp($left->fingerprint, $right->fingerprint);
    }

    /** @param list<array<string, mixed>> $states
     * @return list<array<string, mixed>>
     */
    private function deduplicateStates(array $states): array
    {
        $unique = [];
        foreach ($states as $state) {
            $key = implode("\0", $state['tokens']);
            $unique[$key] ??= $state;
        }

        return array_values($unique);
    }

    /** @param list<string> $terms
     * @param  list<string>  $locales
     * @return array<string, array<string, \stdClass>>
     */
    private function termRows(array $terms, array $locales): array
    {
        $rows = $this->connection()->table($this->spelling->termsTable)
            ->whereIn('locale', $locales)
            ->whereIn('normalized_term', $terms)
            ->select([
                'locale',
                'normalized_term',
                'document_frequency',
                'title_frequency',
                'keyword_frequency',
            ])
            ->orderBy('locale')
            ->orderBy('normalized_term')
            ->limit($this->policy->maximumCandidateRows + count($terms))
            ->get();
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row->locale][(string) $row->normalized_term] = $row;
        }

        return $indexed;
    }

    /** @param list<string> $deleteKeys
     * @param  list<string>  $locales
     * @return list<\stdClass>
     */
    private function deleteRows(array $deleteKeys, array $locales): array
    {
        if ($deleteKeys === []) {
            return [];
        }

        return array_values($this->connection()->table($this->spelling->deletesTable.' as contextual_delete')
            ->join($this->spelling->termsTable.' as contextual_term', 'contextual_term.id', '=', 'contextual_delete.term_id')
            ->whereIn('contextual_delete.locale', $locales)
            ->whereIn('contextual_delete.delete_key', $deleteKeys)
            ->select([
                'contextual_delete.delete_key',
                'contextual_term.locale',
                'contextual_term.normalized_term',
                'contextual_term.document_frequency',
                'contextual_term.title_frequency',
                'contextual_term.keyword_frequency',
            ])
            ->orderBy('contextual_term.locale')
            ->orderByDesc('contextual_term.document_frequency')
            ->orderBy('contextual_term.normalized_term')
            ->limit($this->policy->maximumCandidateRows)
            ->get()
            ->all());
    }

    private function corpusScore(\stdClass $row): int
    {
        return ((int) $row->document_frequency * 100)
            + ((int) $row->title_frequency * 10)
            + (int) $row->keyword_frequency;
    }

    private function originalQuery(QueryVariant $variant): string
    {
        if ($variant->contextualCorrection !== null) {
            return $variant->contextualCorrection->candidate->originalQuery;
        }
        if ($variant->advancedCorrection !== null) {
            return $variant->advancedCorrection->originalQuery;
        }
        if ($variant->keyboardCorrection !== null) {
            return $variant->keyboardCorrection->originalQuery;
        }
        if ($variant->spellingCorrection !== null) {
            return $variant->spellingCorrection->originalQuery;
        }

        return $variant->query;
    }

    private function isProtected(string $token, string $locale): bool
    {
        if (! isset($this->protected[$locale])) {
            $terms = [];
            foreach ($this->spelling->protectedTermsFor($locale) as $protected) {
                foreach ($this->pipeline->prepare($protected, $locale)->tokens as $normalized) {
                    $terms[$normalized] = true;
                }
            }
            $this->protected[$locale] = $terms;
        }

        return isset($this->protected[$locale][$token]);
    }

    private function safeWord(string $token): bool
    {
        return $this->length($token) <= 191
            && preg_match('/\A[\p{L}\p{M}]+(?:[\'’][\p{L}\p{M}]+)*\z/uD', $token) === 1;
    }

    private function unsafeQuery(string $query): bool
    {
        return preg_match('/[\p{C}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $query) === 1
            || preg_match('/(?:https?:\/\/|www\.|[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,})/iu', $query) === 1
            || preg_match('/[_\/\\\\]|[A-Za-z_$][A-Za-z0-9_$]*::|->|=>/', $query) === 1
            || preg_match('/\d[.,]\d/u', $query) === 1;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : count(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private function missingDictionary(QueryException $exception): bool
    {
        $sql = $exception->getSql();
        if (! str_contains($sql, $this->spelling->termsTable)
            && ! str_contains($sql, $this->spelling->deletesTable)) {
            return false;
        }
        $state = isset($exception->errorInfo[0]) ? (string) $exception->errorInfo[0] : (string) $exception->getCode();
        $driver = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;

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
