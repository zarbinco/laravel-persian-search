<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Correction;

use Zarbinco\PersianSearch\Contracts\LanguageCorrectionProfile;
use Zarbinco\PersianSearch\Exceptions\InvalidSpellingConfigurationException;

final readonly class AdvancedCorrectionPolicy
{
    /**
     * @param  list<class-string<LanguageCorrectionProfile>>  $profileClasses
     */
    public function __construct(
        public bool $phoneticEnabled,
        public bool $segmentationEnabled,
        public bool $splitEnabled,
        public bool $mergeEnabled,
        public array $profileClasses,
        public int $maximumTransformationDepth,
        public int $phoneticMinimumTokenLength,
        public int $maximumTokensToInspect,
        public int $maximumTokensToCorrect,
        public int $maximumPhoneticChangesPerToken,
        public int $maximumAlternativesPerToken,
        public int $maximumCandidatesPerToken,
        public int $maximumQueryVariants,
        public int $minimumTokenLength,
        public int $minimumSegmentLength,
        public int $maximumSplitPositionsPerToken,
        public int $maximumAdjacentPairs,
        public int $maximumMergesPerQuery,
        public int $maximumSegmentationCandidates,
        public int $maximumLookupTerms,
        public int $maximumCandidateRows,
        public int $phoneticBaseCost,
        public int $splitBaseCost,
        public int $mergeBaseCost,
    ) {}

    /** @param array<string, mixed> $spelling */
    public static function fromArray(array $spelling): self
    {
        $phonetic = self::section($spelling, 'phonetic');
        $segmentation = self::section($spelling, 'segmentation');
        self::integer($segmentation, 'maximum_segments', 2, 2, 2, 'spelling.segmentation');
        $maximumTokensToInspect = self::integer($phonetic, 'maximum_tokens_to_inspect', 4, 1, 20, 'spelling.phonetic');
        $maximumTokensToCorrect = self::integer($phonetic, 'maximum_tokens_to_correct', 2, 1, 4, 'spelling.phonetic');
        if ($maximumTokensToCorrect > $maximumTokensToInspect) {
            throw InvalidSpellingConfigurationException::forValue(
                'spelling.phonetic.maximum_tokens_to_correct',
                $maximumTokensToCorrect,
                'must not exceed maximum_tokens_to_inspect',
            );
        }
        $minimumTokenLength = self::integer($segmentation, 'minimum_token_length', 6, 2, 191, 'spelling.segmentation');
        $minimumSegmentLength = self::integer($segmentation, 'minimum_segment_length', 2, 1, 95, 'spelling.segmentation');
        if ($minimumTokenLength < $minimumSegmentLength * 2) {
            throw InvalidSpellingConfigurationException::forValue(
                'spelling.segmentation.minimum_token_length',
                $minimumTokenLength,
                'must be at least twice minimum_segment_length',
            );
        }
        $maximumAdjacentPairs = self::integer($segmentation, 'maximum_adjacent_pairs', 4, 1, 20, 'spelling.segmentation');
        $maximumMergesPerQuery = self::integer($segmentation, 'maximum_merges_per_query', 1, 1, 4, 'spelling.segmentation');
        if ($maximumMergesPerQuery > $maximumAdjacentPairs) {
            throw InvalidSpellingConfigurationException::forValue(
                'spelling.segmentation.maximum_merges_per_query',
                $maximumMergesPerQuery,
                'must not exceed maximum_adjacent_pairs',
            );
        }

        return new self(
            phoneticEnabled: self::boolean($phonetic, 'enabled', false, 'spelling.phonetic'),
            segmentationEnabled: self::boolean($segmentation, 'enabled', false, 'spelling.segmentation'),
            splitEnabled: self::boolean($segmentation, 'split_enabled', true, 'spelling.segmentation'),
            mergeEnabled: self::boolean($segmentation, 'merge_enabled', true, 'spelling.segmentation'),
            profileClasses: self::profileClasses($phonetic['profiles'] ?? [
                PersianLanguageCorrectionProfile::class,
                EnglishLanguageCorrectionProfile::class,
            ]),
            maximumTransformationDepth: self::integer($spelling, 'maximum_transformation_depth', 2, 1, 4, 'spelling'),
            phoneticMinimumTokenLength: self::integer($phonetic, 'minimum_token_length', 3, 2, 191, 'spelling.phonetic'),
            maximumTokensToInspect: $maximumTokensToInspect,
            maximumTokensToCorrect: $maximumTokensToCorrect,
            maximumPhoneticChangesPerToken: self::integer($phonetic, 'maximum_changes_per_token', 2, 1, 3, 'spelling.phonetic'),
            maximumAlternativesPerToken: self::integer($phonetic, 'maximum_alternatives_per_token', 32, 1, 256, 'spelling.phonetic'),
            maximumCandidatesPerToken: self::integer($phonetic, 'maximum_candidates_per_token', 5, 1, 50, 'spelling.phonetic'),
            maximumQueryVariants: self::integer($phonetic, 'maximum_query_variants', 5, 1, 20, 'spelling.phonetic'),
            minimumTokenLength: $minimumTokenLength,
            minimumSegmentLength: $minimumSegmentLength,
            maximumSplitPositionsPerToken: self::integer($segmentation, 'maximum_split_positions_per_token', 24, 1, 190, 'spelling.segmentation'),
            maximumAdjacentPairs: $maximumAdjacentPairs,
            maximumMergesPerQuery: $maximumMergesPerQuery,
            maximumSegmentationCandidates: self::integer($segmentation, 'maximum_query_variants', 5, 1, 20, 'spelling.segmentation'),
            maximumLookupTerms: self::integer($spelling, 'maximum_advanced_lookup_terms', 256, 1, 2000, 'spelling'),
            maximumCandidateRows: self::integer($spelling, 'maximum_advanced_candidate_rows', 512, 1, 5000, 'spelling'),
            phoneticBaseCost: self::integer($phonetic, 'base_cost', 1200, 1, 1000000, 'spelling.phonetic'),
            splitBaseCost: self::integer($segmentation, 'split_cost', 1400, 1, 1000000, 'spelling.segmentation'),
            mergeBaseCost: self::integer($segmentation, 'merge_cost', 1500, 1, 1000000, 'spelling.segmentation'),
        );
    }

    public function enabled(): bool
    {
        return $this->phoneticEnabled
            || ($this->segmentationEnabled && ($this->splitEnabled || $this->mergeEnabled));
    }

    public function dictionaryNeedsToken(string $token): bool
    {
        $length = self::length($token);

        return ($this->phoneticEnabled && $length >= $this->phoneticMinimumTokenLength)
            || ($this->segmentationEnabled && $length >= $this->minimumSegmentLength);
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : count(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function section(array $source, string $key): array
    {
        $value = $source[$key] ?? [];
        if (! is_array($value)) {
            throw InvalidSpellingConfigurationException::forValue("spelling.{$key}", $value, 'must be an array');
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

    /** @return list<class-string<LanguageCorrectionProfile>> */
    private static function profileClasses(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidSpellingConfigurationException::forValue('spelling.phonetic.profiles', $value, 'must be a list of language correction profile classes');
        }

        $profiles = [];
        foreach ($value as $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_a($class, LanguageCorrectionProfile::class, true)) {
                throw InvalidSpellingConfigurationException::forValue(
                    'spelling.phonetic.profiles',
                    $value,
                    'must contain classes implementing LanguageCorrectionProfile',
                );
            }
            $profiles[] = $class;
        }

        return array_values(array_unique($profiles));
    }
}
