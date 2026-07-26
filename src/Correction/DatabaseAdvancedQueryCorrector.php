<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Correction;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Zarbinco\PersianSearch\Contracts\AdvancedQueryCorrector;
use Zarbinco\PersianSearch\Contracts\LanguageCorrectionProfile;
use Zarbinco\PersianSearch\Exceptions\SpellingDictionaryUnavailableException;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Spelling\SpellingPolicy;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final class DatabaseAdvancedQueryCorrector implements AdvancedQueryCorrector
{
    /** @var array<string, array<string, true>> */
    private array $protected = [];

    public function __construct(
        private readonly AdvancedCorrectionPolicy $policy,
        private readonly LanguageCorrectionProfileRegistry $profiles,
        private readonly SpellingPolicy $spelling,
        private readonly DatabaseManager $database,
        private readonly SearchTextPipeline $pipeline,
    ) {}

    public function correct(QueryVariant $variant): AdvancedCorrectionCollection
    {
        $maximum = max($this->policy->maximumQueryVariants, $this->policy->maximumSegmentationCandidates);
        $corrections = new AdvancedCorrectionCollection($maximum);
        if (! $this->policy->enabled()) {
            return $corrections;
        }
        if ($this->unsafeQuery($variant->query)) {
            return $corrections;
        }

        $profile = $this->profiles->forLocale($variant->locale);
        if ($profile === null) {
            return $corrections;
        }

        $parentTransformations = $variant->advancedCorrection->transformations ?? [];
        $remainingDepth = $this->policy->maximumTransformationDepth - count($parentTransformations);
        if ($remainingDepth < 1) {
            return $corrections;
        }
        $parentPhoneticCount = count(array_filter(
            $parentTransformations,
            static fn (QueryTransformation $transformation): bool => $transformation->type === CorrectionTransformationType::Phonetic,
        ));
        $remainingPhoneticCorrections = min(
            max(0, $this->policy->maximumTokensToCorrect - $parentPhoneticCount),
            $remainingDepth,
        );

        $parts = $this->tokenParts($variant->query);
        if ($parts === []) {
            return $corrections;
        }

        /** @var list<array{
         *   type: CorrectionTransformationType,
         *   token_index: int,
         *   original_tokens: list<string>,
         *   replacement_tokens: list<string>,
         *   corrected_query: string,
         *   tokens: list<string>,
         *   required_terms: list<string>,
         *   cost: int,
         *   rule: string
         * }> $proposals
         */
        $proposals = [];
        /** @var array<string, true> $lookupTerms */
        $lookupTerms = [];
        foreach ($parts as $part) {
            $this->addLookupTerm($lookupTerms, $part['token']);
        }

        if ($this->policy->phoneticEnabled && $remainingPhoneticCorrections > 0) {
            $this->addPhoneticProposals($variant, $parts, $profile, $proposals, $lookupTerms);
        }
        if ($this->policy->segmentationEnabled && $this->policy->splitEnabled) {
            $this->addSplitProposals($variant, $parts, $profile, $proposals, $lookupTerms);
        }
        if ($this->policy->segmentationEnabled && $this->policy->mergeEnabled) {
            $this->addMergeProposals($variant, $parts, $proposals, $lookupTerms);
        }

        if ($proposals === []) {
            return $corrections;
        }

        try {
            $rows = $this->dictionaryRows(array_keys($lookupTerms), $this->spelling->localeChain($variant->locale));
        } catch (QueryException $exception) {
            if (! $this->isMissingDictionaryTable($exception)) {
                throw $exception;
            }
            if ($this->spelling->failWhenUnavailable) {
                throw new SpellingDictionaryUnavailableException;
            }

            return $corrections;
        }

        $ranked = [];
        $locales = $this->spelling->localeChain($variant->locale);
        foreach ($proposals as $proposal) {
            $original = $proposal['original_tokens'][0];
            if ($proposal['type'] !== CorrectionTransformationType::Merge
                && $this->termExists($rows, $locales, $original)) {
                continue;
            }

            $locale = $this->commonLocale($rows, $locales, $proposal['required_terms']);
            if ($locale === null) {
                continue;
            }

            $frequency = $this->frequency($rows, $locale, $proposal['required_terms']);
            $ranked[] = ['proposal' => $proposal, 'locale' => $locale, 'frequency' => $frequency];
        }

        usort($ranked, static function (array $left, array $right): int {
            $typeOrder = static fn (CorrectionTransformationType $type): int => match ($type) {
                CorrectionTransformationType::Phonetic => 0,
                CorrectionTransformationType::Split => 1,
                CorrectionTransformationType::Merge => 2,
            };

            return $left['proposal']['cost'] <=> $right['proposal']['cost']
                ?: $typeOrder($left['proposal']['type']) <=> $typeOrder($right['proposal']['type'])
                ?: $right['frequency'] <=> $left['frequency']
                ?: strcmp($left['proposal']['corrected_query'], $right['proposal']['corrected_query']);
        });

        $states = $this->correctionStates(
            $variant,
            $parts,
            $ranked,
            $rows,
            $locales,
            $remainingPhoneticCorrections,
        );
        $phoneticCount = 0;
        $segmentationCount = 0;
        foreach ($states as $state) {
            if ($state['type'] === CorrectionTransformationType::Phonetic) {
                if ($phoneticCount >= $this->policy->maximumQueryVariants) {
                    continue;
                }
                $phoneticCount++;
            } else {
                if ($segmentationCount >= $this->policy->maximumSegmentationCandidates) {
                    continue;
                }
                $segmentationCount++;
            }

            $transformations = $parentTransformations;
            foreach ($state['proposals'] as $proposal) {
                $transformations[] = new QueryTransformation(
                    type: $proposal['type'],
                    tokenIndex: $proposal['token_index'],
                    originalTokens: $proposal['original_tokens'],
                    replacementTokens: $proposal['replacement_tokens'],
                    weightedCost: $proposal['cost'],
                    profile: $profile->locale(),
                    rule: $proposal['rule'],
                );
            }
            $depth = count($transformations);
            $fingerprint = hash('sha256', implode("\0", [
                'advanced',
                $variant->fingerprint,
                $state['type']->value,
                $state['corrected_query'],
                $state['locale'],
                (string) $state['cost'],
                (string) $depth,
            ]));

            $corrections->add(new AdvancedCorrection(
                originalQuery: $variant->advancedCorrection->originalQuery
                    ?? $variant->keyboardCorrection->originalQuery
                    ?? $variant->spellingCorrection->originalQuery
                    ?? $variant->query,
                normalizedQuery: $variant->query,
                correctedQuery: $state['corrected_query'],
                locale: $state['locale'],
                tokens: $state['tokens'],
                transformations: $transformations,
                weightedCost: ($variant->advancedCorrection->weightedCost ?? 0) + $state['cost'],
                transformationDepth: $depth,
                fingerprint: $fingerprint,
            ));
        }

        return $corrections;
    }

    /**
     * @param  list<array{token: string, start: int, end: int, gap_before: string}>  $parts
     * @param list<array{
     *   proposal: array{
     *     type: CorrectionTransformationType,
     *     token_index: int,
     *     original_tokens: list<string>,
     *     replacement_tokens: list<string>,
     *     corrected_query: string,
     *     tokens: list<string>,
     *     required_terms: list<string>,
     *     cost: int,
     *     rule: string
     *   },
     *   locale: string,
     *   frequency: int
     * }> $ranked
     * @param  array<string, array<string, \stdClass>>  $rows
     * @param  list<string>  $locales
     * @return list<array{
     *   type: CorrectionTransformationType,
     *   proposals: list<array{
     *     type: CorrectionTransformationType,
     *     token_index: int,
     *     original_tokens: list<string>,
     *     replacement_tokens: list<string>,
     *     corrected_query: string,
     *     tokens: list<string>,
     *     required_terms: list<string>,
     *     cost: int,
     *     rule: string
     *   }>,
     *   corrected_query: string,
     *   tokens: list<string>,
     *   locale: string,
     *   cost: int,
     *   frequency: int,
     *   unresolved: int
     * }>
     */
    private function correctionStates(
        QueryVariant $variant,
        array $parts,
        array $ranked,
        array $rows,
        array $locales,
        int $remainingPhoneticCorrections,
    ): array {
        $phoneticByIndex = [];
        $segmentation = [];
        foreach ($ranked as $candidate) {
            $proposal = $candidate['proposal'];
            if ($proposal['type'] !== CorrectionTransformationType::Phonetic) {
                continue;
            }
            $index = $proposal['token_index'];
            if (count($phoneticByIndex[$index] ?? []) < $this->policy->maximumCandidatesPerToken) {
                $phoneticByIndex[$index][] = $candidate;
            }
        }

        foreach ($ranked as $candidate) {
            $proposal = $candidate['proposal'];
            if ($proposal['type'] === CorrectionTransformationType::Phonetic) {
                continue;
            }
            $mergeCount = $proposal['type'] === CorrectionTransformationType::Merge ? 1 : 0;
            if ($mergeCount > $this->policy->maximumMergesPerQuery) {
                continue;
            }
            $segmentation[] = [
                'type' => $proposal['type'],
                'proposals' => [$proposal],
                'corrected_query' => $proposal['corrected_query'],
                'tokens' => $proposal['tokens'],
                'locale' => $candidate['locale'],
                'cost' => $proposal['cost'],
                'frequency' => $candidate['frequency'],
                'unresolved' => count($phoneticByIndex),
            ];
        }

        $phonetic = $this->phoneticStates(
            $variant,
            $parts,
            $phoneticByIndex,
            $rows,
            $locales,
            $remainingPhoneticCorrections,
        );
        $states = [...$phonetic, ...$segmentation];
        usort($states, static function (array $left, array $right): int {
            $typeOrder = static fn (CorrectionTransformationType $type): int => match ($type) {
                CorrectionTransformationType::Phonetic => 0,
                CorrectionTransformationType::Split => 1,
                CorrectionTransformationType::Merge => 2,
            };

            return $left['unresolved'] <=> $right['unresolved']
                ?: $left['cost'] <=> $right['cost']
                ?: $typeOrder($left['type']) <=> $typeOrder($right['type'])
                ?: $right['frequency'] <=> $left['frequency']
                ?: strcmp($left['corrected_query'], $right['corrected_query']);
        });

        return $states;
    }

    /**
     * @param  list<array{token: string, start: int, end: int, gap_before: string}>  $parts
     * @param array<int, list<array{
     *   proposal: array{
     *     type: CorrectionTransformationType,
     *     token_index: int,
     *     original_tokens: list<string>,
     *     replacement_tokens: list<string>,
     *     corrected_query: string,
     *     tokens: list<string>,
     *     required_terms: list<string>,
     *     cost: int,
     *     rule: string
     *   },
     *   locale: string,
     *   frequency: int
     * }>> $optionsByIndex
     * @param  array<string, array<string, \stdClass>>  $rows
     * @param  list<string>  $locales
     * @return list<array{
     *   type: CorrectionTransformationType,
     *   proposals: list<array{
     *     type: CorrectionTransformationType,
     *     token_index: int,
     *     original_tokens: list<string>,
     *     replacement_tokens: list<string>,
     *     corrected_query: string,
     *     tokens: list<string>,
     *     required_terms: list<string>,
     *     cost: int,
     *     rule: string
     *   }>,
     *   corrected_query: string,
     *   tokens: list<string>,
     *   locale: string,
     *   cost: int,
     *   frequency: int,
     *   unresolved: int
     * }>
     */
    private function phoneticStates(
        QueryVariant $variant,
        array $parts,
        array $optionsByIndex,
        array $rows,
        array $locales,
        int $maximumCorrections,
    ): array {
        if ($optionsByIndex === [] || $maximumCorrections < 1) {
            return [];
        }
        ksort($optionsByIndex);

        $states = [[
            'tokens' => array_column($parts, 'token'),
            'replacements' => [],
            'transformations' => [],
            'weighted_cost' => 0,
            'frequency' => 0,
            'corrected_token_count' => 0,
            'unresolved_count' => count($optionsByIndex),
        ]];

        foreach (array_keys($optionsByIndex) as $index) {
            $expanded = [];
            foreach ($states as $state) {
                $expanded[] = $state;
                if ($state['corrected_token_count'] >= $maximumCorrections) {
                    continue;
                }

                foreach ($optionsByIndex[$index] as $candidate) {
                    $next = $state;
                    $replacement = $candidate['proposal']['replacement_tokens'][0];
                    $next['tokens'][$index] = $replacement;
                    $next['replacements'][$index] = $replacement;
                    $next['transformations'][] = $candidate['proposal'];
                    $next['weighted_cost'] += $candidate['proposal']['cost'];
                    $next['frequency'] += $candidate['frequency'];
                    $next['corrected_token_count']++;
                    $next['unresolved_count']--;
                    $expanded[] = $next;
                }
            }

            usort($expanded, static function (array $left, array $right): int {
                return $left['unresolved_count'] <=> $right['unresolved_count']
                    ?: $left['weighted_cost'] <=> $right['weighted_cost']
                    ?: $right['frequency'] <=> $left['frequency']
                    ?: strcmp(self::replacementKey($left['replacements']), self::replacementKey($right['replacements']));
            });
            $states = array_slice(
                $this->deduplicatePhoneticStates($expanded),
                0,
                max(20, $this->policy->maximumQueryVariants * 4),
            );
        }

        $accepted = [];
        foreach ($states as $state) {
            if ($state['transformations'] === []) {
                continue;
            }
            $requiredTerms = [];
            foreach ($state['transformations'] as $proposal) {
                array_push($requiredTerms, ...$proposal['required_terms']);
            }
            $locale = $this->commonLocale($rows, $locales, array_values(array_unique($requiredTerms)));
            if ($locale === null) {
                continue;
            }
            $correctedQuery = $this->replaceTokenParts($variant->query, $parts, $state['replacements']);
            $accepted[] = [
                'type' => CorrectionTransformationType::Phonetic,
                'proposals' => $state['transformations'],
                'corrected_query' => $correctedQuery,
                'tokens' => $state['tokens'],
                'locale' => $locale,
                'cost' => $state['weighted_cost'],
                'frequency' => $this->frequency($rows, $locale, $requiredTerms),
                'unresolved' => $state['unresolved_count'],
            ];
        }

        return $accepted;
    }

    /**
     * @param  list<array<string, mixed>>  $states
     * @return list<array<string, mixed>>
     */
    private function deduplicatePhoneticStates(array $states): array
    {
        $seen = [];
        $unique = [];
        foreach ($states as $state) {
            $key = self::replacementKey($state['replacements']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $state;
        }

        return $unique;
    }

    /** @param array<int, string> $replacements */
    private static function replacementKey(array $replacements): string
    {
        $parts = [];
        foreach ($replacements as $index => $replacement) {
            $parts[] = $index."\0".$replacement;
        }

        return implode("\0", $parts);
    }

    /**
     * @param  list<array{token: string, start: int, end: int, gap_before: string}>  $parts
     * @param  array<int, string>  $replacements
     */
    private function replaceTokenParts(string $query, array $parts, array $replacements): string
    {
        krsort($replacements);
        foreach ($replacements as $index => $replacement) {
            $query = $this->replaceRange(
                $query,
                $parts[$index]['start'],
                $parts[$index]['end'],
                $replacement,
            );
        }

        return $query;
    }

    /**
     * @param  list<array{token: string, start: int, end: int, gap_before: string}>  $parts
     * @param list<array{
     *   type: CorrectionTransformationType,
     *   token_index: int,
     *   original_tokens: list<string>,
     *   replacement_tokens: list<string>,
     *   corrected_query: string,
     *   tokens: list<string>,
     *   required_terms: list<string>,
     *   cost: int,
     *   rule: string
     * }> $proposals
     * @param  array<string, true>  $lookupTerms
     */
    private function addPhoneticProposals(
        QueryVariant $variant,
        array $parts,
        LanguageCorrectionProfile $profile,
        array &$proposals,
        array &$lookupTerms,
    ): void {
        $inspected = 0;
        foreach ($parts as $index => $part) {
            if ($inspected >= $this->policy->maximumTokensToInspect) {
                break;
            }
            $token = $part['token'];
            if (! $this->safeWord($token)
                || $this->length($token) < $this->policy->phoneticMinimumTokenLength
                || $this->isProtected($token, $variant->locale)) {
                continue;
            }
            $inspected++;

            /** @var list<array{token: string, cost: int, rules: list<string>}> $frontier */
            $frontier = [['token' => $token, 'cost' => 0, 'rules' => []]];
            $seen = [$token => true];
            $generated = 0;

            for ($depth = 1; $depth <= $this->policy->maximumPhoneticChangesPerToken; $depth++) {
                $next = [];
                foreach ($frontier as $state) {
                    foreach ($profile->phoneticAlternatives($state['token']) as $alternative) {
                        $generated++;
                        if ($generated > $this->policy->maximumAlternativesPerToken) {
                            break 3;
                        }
                        if (isset($seen[$alternative->token]) || ! $this->safeWord($alternative->token)
                            || $this->length($alternative->token) > 191) {
                            continue;
                        }
                        $seen[$alternative->token] = true;
                        $rules = [...$state['rules'], $alternative->rule];
                        $cost = $state['cost'] + $alternative->cost + $this->policy->phoneticBaseCost;
                        $corrected = $this->replaceRange(
                            $variant->query,
                            $part['start'],
                            $part['end'],
                            $alternative->token,
                        );
                        $proposal = [
                            'type' => CorrectionTransformationType::Phonetic,
                            'token_index' => $index,
                            'original_tokens' => [$token],
                            'replacement_tokens' => [$alternative->token],
                            'corrected_query' => $corrected,
                            'tokens' => $this->tokens($corrected),
                            'required_terms' => [$alternative->token],
                            'cost' => $cost,
                            'rule' => implode('+', $rules),
                        ];
                        if ($this->appendProposal($proposals, $lookupTerms, $proposal)) {
                            $next[] = ['token' => $alternative->token, 'cost' => $state['cost'] + $alternative->cost, 'rules' => $rules];
                        }
                    }
                }
                $frontier = $next;
                if ($frontier === []) {
                    break;
                }
            }
        }
    }

    /**
     * @param  list<array{token: string, start: int, end: int, gap_before: string}>  $parts
     * @param list<array{
     *   type: CorrectionTransformationType,
     *   token_index: int,
     *   original_tokens: list<string>,
     *   replacement_tokens: list<string>,
     *   corrected_query: string,
     *   tokens: list<string>,
     *   required_terms: list<string>,
     *   cost: int,
     *   rule: string
     * }> $proposals
     * @param  array<string, true>  $lookupTerms
     */
    private function addSplitProposals(
        QueryVariant $variant,
        array $parts,
        LanguageCorrectionProfile $profile,
        array &$proposals,
        array &$lookupTerms,
    ): void {
        $separator = $profile->separators()[0] ?? ' ';
        foreach ($parts as $index => $part) {
            $token = $part['token'];
            if (! $this->safeWord($token)
                || $this->length($token) < $this->policy->minimumTokenLength
                || $this->isProtected($token, $variant->locale)) {
                continue;
            }

            $characters = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $positions = 0;
            for ($position = $this->policy->minimumSegmentLength;
                $position <= count($characters) - $this->policy->minimumSegmentLength;
                $position++) {
                if ($positions >= $this->policy->maximumSplitPositionsPerToken) {
                    break;
                }
                $positions++;
                $left = implode('', array_slice($characters, 0, $position));
                $right = implode('', array_slice($characters, $position));
                $corrected = $this->replaceRange($variant->query, $part['start'], $part['end'], $left.$separator.$right);
                $proposal = [
                    'type' => CorrectionTransformationType::Split,
                    'token_index' => $index,
                    'original_tokens' => [$token],
                    'replacement_tokens' => [$left, $right],
                    'corrected_query' => $corrected,
                    'tokens' => $this->tokens($corrected),
                    'required_terms' => [$left, $right],
                    'cost' => $this->policy->splitBaseCost,
                    'rule' => 'dictionary_split',
                ];
                $this->appendProposal($proposals, $lookupTerms, $proposal);
            }
        }
    }

    /**
     * @param  list<array{token: string, start: int, end: int, gap_before: string}>  $parts
     * @param list<array{
     *   type: CorrectionTransformationType,
     *   token_index: int,
     *   original_tokens: list<string>,
     *   replacement_tokens: list<string>,
     *   corrected_query: string,
     *   tokens: list<string>,
     *   required_terms: list<string>,
     *   cost: int,
     *   rule: string
     * }> $proposals
     * @param  array<string, true>  $lookupTerms
     */
    private function addMergeProposals(
        QueryVariant $variant,
        array $parts,
        array &$proposals,
        array &$lookupTerms,
    ): void {
        $inspected = 0;
        for ($index = 0; $index < count($parts) - 1; $index++) {
            if ($inspected >= $this->policy->maximumAdjacentPairs) {
                break;
            }
            $left = $parts[$index];
            $right = $parts[$index + 1];
            $inspected++;

            if (preg_match('/\A\h+\z/uD', $right['gap_before']) !== 1
                || ! $this->safeWord($left['token']) || ! $this->safeWord($right['token'])
                || $this->isProtected($left['token'], $variant->locale)
                || $this->isProtected($right['token'], $variant->locale)) {
                continue;
            }

            $merged = $left['token'].$right['token'];
            if ($this->length($merged) > 191 || $this->isProtected($merged, $variant->locale)) {
                continue;
            }
            $corrected = $this->replaceRange($variant->query, $left['start'], $right['end'], $merged);
            $proposal = [
                'type' => CorrectionTransformationType::Merge,
                'token_index' => $index,
                'original_tokens' => [$left['token'], $right['token']],
                'replacement_tokens' => [$merged],
                'corrected_query' => $corrected,
                'tokens' => $this->tokens($corrected),
                'required_terms' => [$merged],
                'cost' => $this->policy->mergeBaseCost,
                'rule' => 'dictionary_merge',
            ];
            $this->appendProposal($proposals, $lookupTerms, $proposal);
        }
    }

    /**
     * @param list<array{
     *   type: CorrectionTransformationType,
     *   token_index: int,
     *   original_tokens: list<string>,
     *   replacement_tokens: list<string>,
     *   corrected_query: string,
     *   tokens: list<string>,
     *   required_terms: list<string>,
     *   cost: int,
     *   rule: string
     * }> $proposals
     * @param  array<string, true>  $lookupTerms
     * @param array{
     *   type: CorrectionTransformationType,
     *   token_index: int,
     *   original_tokens: list<string>,
     *   replacement_tokens: list<string>,
     *   corrected_query: string,
     *   tokens: list<string>,
     *   required_terms: list<string>,
     *   cost: int,
     *   rule: string
     * } $proposal
     */
    private function appendProposal(array &$proposals, array &$lookupTerms, array $proposal): bool
    {
        $newTerms = array_values(array_filter(
            $proposal['required_terms'],
            static fn (string $term): bool => ! isset($lookupTerms[$term]),
        ));
        if (count($lookupTerms) + count($newTerms) > $this->policy->maximumLookupTerms) {
            return false;
        }
        foreach ($newTerms as $term) {
            $lookupTerms[$term] = true;
        }
        $proposals[] = $proposal;

        return true;
    }

    /** @param array<string, true> $lookupTerms */
    private function addLookupTerm(array &$lookupTerms, string $term): void
    {
        if (count($lookupTerms) < $this->policy->maximumLookupTerms) {
            $lookupTerms[$term] = true;
        }
    }

    /**
     * @param  list<string>  $terms
     * @param  list<string>  $locales
     * @return array<string, array<string, \stdClass>>
     */
    private function dictionaryRows(array $terms, array $locales): array
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
            ->limit($this->policy->maximumCandidateRows)
            ->get();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row->locale][(string) $row->normalized_term] = $row;
        }

        return $indexed;
    }

    /**
     * @param  array<string, array<string, \stdClass>>  $rows
     * @param  list<string>  $locales
     */
    private function termExists(array $rows, array $locales, string $term): bool
    {
        foreach ($locales as $locale) {
            if (isset($rows[$locale][$term])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, \stdClass>>  $rows
     * @param  list<string>  $locales
     * @param  list<string>  $terms
     */
    private function commonLocale(array $rows, array $locales, array $terms): ?string
    {
        foreach ($locales as $locale) {
            foreach ($terms as $term) {
                if (! isset($rows[$locale][$term])) {
                    continue 2;
                }
            }

            return $locale;
        }

        return null;
    }

    /**
     * @param  array<string, array<string, \stdClass>>  $rows
     * @param  list<string>  $terms
     */
    private function frequency(array $rows, string $locale, array $terms): int
    {
        $frequency = 0;
        foreach ($terms as $term) {
            $row = $rows[$locale][$term];
            $frequency += ((int) $row->document_frequency * 100)
                + ((int) $row->title_frequency * 10)
                + (int) $row->keyword_frequency;
        }

        return $frequency;
    }

    /** @return list<array{token: string, start: int, end: int, gap_before: string}> */
    private function tokenParts(string $query): array
    {
        preg_match_all(
            "/[\p{L}\p{N}][\p{L}\p{M}\p{N}]*(?:['’][\p{L}\p{N}][\p{L}\p{M}\p{N}]*)*/u",
            $query,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $parts = [];
        $previousEnd = 0;
        foreach ($matches[0] as $match) {
            if ($match[0] === '') {
                continue;
            }
            $token = $match[0];
            $offset = $match[1];
            $end = $offset + strlen($token);
            $parts[] = [
                'token' => $token,
                'start' => $offset,
                'end' => $end,
                'gap_before' => substr($query, $previousEnd, $offset - $previousEnd),
            ];
            $previousEnd = $end;
        }

        return $parts;
    }

    /** @return list<string> */
    private function tokens(string $query): array
    {
        return array_map(
            static fn (array $part): string => $part['token'],
            $this->tokenParts($query),
        );
    }

    private function replaceRange(string $query, int $start, int $end, string $replacement): string
    {
        return substr($query, 0, $start).$replacement.substr($query, $end);
    }

    private function safeWord(string $token): bool
    {
        return preg_match('/\A[\p{L}\p{M}]+\z/uD', $token) === 1;
    }

    private function unsafeQuery(string $query): bool
    {
        return preg_match(
            '/(?:[a-z][a-z0-9+.-]*:\/\/|www\.|@|_|[0-9][.,][0-9]|[\p{L}\p{N}]+-[\p{L}\p{N}]+|\p{L}\p{N}|\p{N}\p{L})/iu',
            $query,
        ) === 1;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : count(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
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

    private function isMissingDictionaryTable(QueryException $exception): bool
    {
        if (! str_contains($exception->getSql(), $this->spelling->termsTable)) {
            return false;
        }

        $errorInfo = $exception->errorInfo;
        $sqlState = is_array($errorInfo) && isset($errorInfo[0])
            ? (string) $errorInfo[0]
            : (string) $exception->getCode();
        if (in_array($sqlState, ['42P01', '42S02'], true)) {
            return true;
        }
        $driverCode = is_array($errorInfo) && isset($errorInfo[1]) ? (int) $errorInfo[1] : 0;

        return $sqlState === 'HY000'
            && $driverCode === 1
            && str_contains(strtolower($exception->getMessage()), 'no such table');
    }

    private function connection(): Connection
    {
        return $this->database->connection($this->spelling->connection ?? config('persian-search.index.connection'));
    }
}
