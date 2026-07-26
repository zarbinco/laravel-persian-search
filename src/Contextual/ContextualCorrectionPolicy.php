<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use Zarbinco\PersianSearch\Exceptions\InvalidSpellingConfigurationException;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class ContextualCorrectionPolicy
{
    public function __construct(
        public bool $enabled,
        public bool $ngramsEnabled,
        public bool $resultCountsEnabled,
        public bool $autoApplyRecommendationEnabled,
        public ?string $connection,
        public string $ngramsTable,
        public string $ngramStagingTable,
        public string $buildsTable,
        public bool $buildNgrams,
        public int $maximumGramSize,
        public int $minimumNgramDocumentFrequency,
        public int $maximumTermsPerDocument,
        public int $maximumNgramsPerDocument,
        public int $insertBatchSize,
        public int $maximumDirectResults,
        public bool $evaluateWhenZeroResults,
        public bool $evaluateWhenLowResults,
        public bool $evaluateOnPreview,
        public int $minimumConfidenceBasisPoints,
        public int $minimumAbsoluteResultGain,
        public int $minimumResultGainRatioBasisPoints,
        public int $autoApplyMinimumConfidenceBasisPoints,
        public bool $autoApplyRequiresZeroDirectResults,
        public int $minimumCorpusGain,
        public int $minimumContextGain,
        public int $maximumTokensToInspect,
        public int $maximumTokensToCorrect,
        public int $maximumCandidatesPerToken,
        public int $maximumCandidatesPerQuery,
        public int $maximumResultCountCandidates,
        public int $maximumContextLookups,
        public int $maximumTransformationDepth,
        public int $maximumQueryLength,
        public int $maximumQueryTokens,
        public int $resultCountCap,
        public int $maximumCandidateRows,
        public int $maximumDeleteKeys,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $build = self::section($config, 'build');
        $trigger = self::section($config, 'trigger');
        $decision = self::section($config, 'decision');
        $limits = self::section($config, 'limits');

        $maximumTokensToInspect = self::integer($limits, 'maximum_tokens_to_inspect', 5, 1, 20, 'contextual.limits');
        $maximumTokensToCorrect = self::integer($limits, 'maximum_tokens_to_correct', 2, 1, 4, 'contextual.limits');
        if ($maximumTokensToCorrect > $maximumTokensToInspect) {
            throw InvalidSpellingConfigurationException::forValue(
                'contextual.limits.maximum_tokens_to_correct',
                $maximumTokensToCorrect,
                'must not exceed maximum_tokens_to_inspect',
            );
        }
        $maximumCandidatesPerToken = self::integer($limits, 'maximum_candidates_per_token', 3, 1, 20, 'contextual.limits');
        $maximumCandidatesPerQuery = self::integer($limits, 'maximum_candidates_per_query', 5, 1, 20, 'contextual.limits');
        $maximumResultCandidates = self::integer($limits, 'maximum_result_count_candidates', 3, 1, 20, 'contextual.limits');
        if ($maximumResultCandidates > $maximumCandidatesPerQuery) {
            throw InvalidSpellingConfigurationException::forValue(
                'contextual.limits.maximum_result_count_candidates',
                $maximumResultCandidates,
                'must not exceed maximum_candidates_per_query',
            );
        }
        $minimumConfidence = self::integer($decision, 'minimum_confidence_basis_points', 7500, 1, 10000, 'contextual.decision');
        $autoConfidence = self::integer($decision, 'auto_apply_minimum_confidence_basis_points', 9000, 1, 10000, 'contextual.decision');
        if ($autoConfidence < $minimumConfidence) {
            throw InvalidSpellingConfigurationException::forValue(
                'contextual.decision.auto_apply_minimum_confidence_basis_points',
                $autoConfidence,
                'must be at least minimum_confidence_basis_points',
            );
        }
        $ngramsTable = self::tableName(
            $config['ngrams_table'] ?? 'persian_search_dictionary_ngrams',
            'contextual.ngrams_table',
        );
        $ngramStagingTable = self::tableName(
            $config['ngram_staging_table'] ?? 'persian_search_dictionary_ngram_staging',
            'contextual.ngram_staging_table',
        );
        $buildsTable = self::tableName(
            $config['builds_table'] ?? 'persian_search_contextual_builds',
            'contextual.builds_table',
        );
        if (count(array_unique([$ngramsTable, $ngramStagingTable, $buildsTable])) !== 3) {
            throw InvalidSpellingConfigurationException::forValue(
                'contextual.tables',
                [$ngramsTable, $ngramStagingTable, $buildsTable],
                'must use distinct final, staging, and build metadata table names',
            );
        }

        return new self(
            enabled: self::boolean($config, 'enabled', false, 'contextual'),
            ngramsEnabled: self::boolean($config, 'ngrams_enabled', true, 'contextual'),
            resultCountsEnabled: self::boolean($config, 'result_counts_enabled', true, 'contextual'),
            autoApplyRecommendationEnabled: self::boolean($config, 'auto_apply_recommendation_enabled', false, 'contextual'),
            connection: self::nullableName($config['connection'] ?? null, 'contextual.connection'),
            ngramsTable: $ngramsTable,
            ngramStagingTable: $ngramStagingTable,
            buildsTable: $buildsTable,
            buildNgrams: self::boolean($build, 'enabled', true, 'contextual.build'),
            maximumGramSize: self::integer($build, 'maximum_gram_size', 2, 2, 2, 'contextual.build'),
            minimumNgramDocumentFrequency: self::integer($build, 'minimum_document_frequency', 1, 1, PHP_INT_MAX, 'contextual.build'),
            maximumTermsPerDocument: self::integer($build, 'maximum_terms_per_document', 200, 2, 5000, 'contextual.build'),
            maximumNgramsPerDocument: self::integer($build, 'maximum_ngrams_per_document', 400, 1, 10000, 'contextual.build'),
            insertBatchSize: self::integer($build, 'insert_batch_size', 500, 1, 5000, 'contextual.build'),
            maximumDirectResults: self::integer($trigger, 'maximum_direct_results', 3, 0, 100, 'contextual.trigger'),
            evaluateWhenZeroResults: self::boolean($trigger, 'evaluate_when_zero_results', true, 'contextual.trigger'),
            evaluateWhenLowResults: self::boolean($trigger, 'evaluate_when_low_results', true, 'contextual.trigger'),
            evaluateOnPreview: self::boolean($trigger, 'evaluate_on_preview', false, 'contextual.trigger'),
            minimumConfidenceBasisPoints: $minimumConfidence,
            minimumAbsoluteResultGain: self::integer($decision, 'minimum_absolute_result_gain', 3, 1, 1000, 'contextual.decision'),
            minimumResultGainRatioBasisPoints: self::integer(
                $decision,
                'minimum_result_gain_ratio_basis_points',
                30000,
                10000,
                1000000,
                'contextual.decision',
            ),
            autoApplyMinimumConfidenceBasisPoints: $autoConfidence,
            autoApplyRequiresZeroDirectResults: self::boolean(
                $decision,
                'auto_apply_requires_zero_direct_results',
                true,
                'contextual.decision',
            ),
            minimumCorpusGain: self::integer($decision, 'minimum_corpus_gain', 1, 0, PHP_INT_MAX, 'contextual.decision'),
            minimumContextGain: self::integer($decision, 'minimum_context_gain', 0, 0, PHP_INT_MAX, 'contextual.decision'),
            maximumTokensToInspect: $maximumTokensToInspect,
            maximumTokensToCorrect: $maximumTokensToCorrect,
            maximumCandidatesPerToken: $maximumCandidatesPerToken,
            maximumCandidatesPerQuery: $maximumCandidatesPerQuery,
            maximumResultCountCandidates: $maximumResultCandidates,
            maximumContextLookups: self::integer($limits, 'maximum_context_lookups', 20, 1, 200, 'contextual.limits'),
            maximumTransformationDepth: self::integer($limits, 'maximum_transformation_depth', 3, 1, 6, 'contextual.limits'),
            maximumQueryLength: self::integer($limits, 'maximum_query_length', 200, 2, 2000, 'contextual.limits'),
            maximumQueryTokens: self::integer($limits, 'maximum_query_tokens', 20, 1, 100, 'contextual.limits'),
            resultCountCap: self::integer($limits, 'result_count_cap', 11, 1, 1000, 'contextual.limits'),
            maximumCandidateRows: self::integer($limits, 'maximum_candidate_rows', 300, 1, 5000, 'contextual.limits'),
            maximumDeleteKeys: self::integer($limits, 'maximum_delete_keys', 512, 1, 5000, 'contextual.limits'),
        );
    }

    public function shouldEvaluate(CandidateResultCount $direct, bool $preview): bool
    {
        if (! $this->enabled || ($preview && ! $this->evaluateOnPreview)) {
            return false;
        }
        if ($direct->count > $this->maximumDirectResults) {
            return false;
        }

        return $direct->count === 0
            ? $this->evaluateWhenZeroResults
            : $this->evaluateWhenLowResults;
    }

    /** @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function section(array $source, string $key): array
    {
        $value = $source[$key] ?? [];
        if (! is_array($value)) {
            throw InvalidSpellingConfigurationException::forValue("contextual.{$key}", $value, 'must be an array');
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private static function boolean(array $source, string $key, bool $default, string $prefix): bool
    {
        $value = $source[$key] ?? $default;
        if (! is_bool($value)) {
            throw InvalidSpellingConfigurationException::forValue("{$prefix}.{$key}", $value, 'must be a boolean');
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private static function integer(
        array $source,
        string $key,
        int $default,
        int $minimum,
        int $maximum,
        string $prefix,
    ): int {
        $value = $source[$key] ?? $default;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw InvalidSpellingConfigurationException::forValue(
                "{$prefix}.{$key}",
                $value,
                "must be an integer between {$minimum} and {$maximum}",
            );
        }

        return $value;
    }

    private static function nullableName(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! CanonicalConfigurationName::isValid($value)) {
            throw InvalidSpellingConfigurationException::forValue($key, $value, 'must be a safe non-empty name or null');
        }

        return $value;
    }

    private static function tableName(mixed $value, string $key): string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $value) !== 1) {
            throw InvalidSpellingConfigurationException::forValue(
                $key,
                $value,
                'must be a safe unqualified database identifier',
            );
        }

        return $value;
    }
}
