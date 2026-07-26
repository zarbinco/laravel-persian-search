<?php

namespace Zarbinco\PersianSearch\Spelling;

use Zarbinco\PersianSearch\Exceptions\InvalidSpellingConfigurationException;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SpellingPolicy
{
    /**
     * @param  array<string, array<string, list<string>>>  $adjacentKeys
     * @param  array<string, list<string>>  $protectedTerms
     */
    public function __construct(
        public bool $enabled,
        public ?string $connection,
        public string $termsTable,
        public string $deletesTable,
        public int $minimumTokenLength,
        public int $maximumTokenLength,
        public int $maximumEditDistance,
        public int $twoEditDistanceMinimumLength,
        public int $maximumCandidatesPerToken,
        public int $maximumCandidateRowsPerToken,
        public int $maximumCandidateRowsPerQuery,
        public int $maximumQueryVariants,
        public int $maximumTokensToInspect,
        public int $maximumTokensToCorrect,
        public int $maximumDeleteKeysPerQueryToken,
        public int $maximumDeleteKeysPerQuery,
        public int $maximumDeletesPerDictionaryTerm,
        public int $minimumDocumentFrequency,
        public int $maximumDictionaryTerms,
        public int $buildChunkSize,
        public int $insertBatchSize,
        public int $insertionCost,
        public int $deletionCost,
        public int $substitutionCost,
        public int $transpositionCost,
        public int $adjacentKeySubstitutionCost,
        public bool $failWhenUnavailable,
        public array $adjacentKeys,
        public array $protectedTerms,
    ) {}

    /** @param  array<string, mixed>  $config */
    public static function fromArray(array $config): self
    {
        $dictionary = self::array($config, 'dictionary', 'spelling');
        $correction = self::array($config, 'correction', 'spelling');
        $costs = self::array($correction, 'costs', 'spelling.correction');

        $enabled = self::boolean($config, 'enabled', false);
        $connection = self::nullableName($config['connection'] ?? null, 'spelling.connection');
        $termsTable = self::tableName($config['terms_table'] ?? 'persian_search_dictionary_terms', 'spelling.terms_table');
        $deletesTable = self::tableName($config['deletes_table'] ?? 'persian_search_dictionary_deletes', 'spelling.deletes_table');

        $minimumTokenLength = self::integer($dictionary, 'minimum_token_length', 4, 1, 191, 'spelling.dictionary');
        $maximumTokenLength = self::integer($dictionary, 'maximum_token_length', 64, $minimumTokenLength, 191, 'spelling.dictionary');
        $maximumEditDistance = self::integer($correction, 'maximum_edit_distance', 2, 1, 3, 'spelling.correction');
        $twoEditDistanceMinimumLength = self::integer(
            $correction,
            'two_edit_distance_minimum_length',
            8,
            $minimumTokenLength,
            $maximumTokenLength + 1,
            'spelling.correction',
        );

        $maximumCandidatesPerToken = self::integer($correction, 'maximum_candidates_per_token', 5, 1, 50, 'spelling.correction');
        $maximumCandidateRowsPerToken = self::integer(
            $correction,
            'maximum_candidate_rows_per_token',
            250,
            $maximumCandidatesPerToken,
            5000,
            'spelling.correction',
        );
        $maximumCandidateRowsPerQuery = self::integer(
            $correction,
            'maximum_candidate_rows_per_query',
            500,
            $maximumCandidateRowsPerToken,
            10000,
            'spelling.correction',
        );
        $maximumQueryVariants = self::integer($correction, 'maximum_query_variants', 5, 1, 20, 'spelling.correction');
        $maximumTokensToInspect = self::integer($correction, 'maximum_tokens_to_inspect', 4, 1, 20, 'spelling.correction');
        $maximumTokensToCorrect = self::integer(
            $correction,
            'maximum_tokens_to_correct',
            2,
            1,
            $maximumTokensToInspect,
            'spelling.correction',
        );
        $maximumDeleteKeysPerQueryToken = self::integer($correction, 'maximum_delete_keys_per_query_token', 128, 1, 5000, 'spelling.correction');
        $maximumDeleteKeysPerQuery = self::integer(
            $correction,
            'maximum_delete_keys_per_query',
            512,
            $maximumDeleteKeysPerQueryToken,
            5000,
            'spelling.correction',
        );
        $maximumDeletesPerDictionaryTerm = self::integer($dictionary, 'maximum_deletes_per_term', 256, 1, 5000, 'spelling.dictionary');
        $minimumDocumentFrequency = self::integer($dictionary, 'minimum_document_frequency', 1, 1, PHP_INT_MAX, 'spelling.dictionary');
        $maximumDictionaryTerms = self::integer($dictionary, 'maximum_terms', 100000, 1, 10000000, 'spelling.dictionary');
        $buildChunkSize = self::integer($dictionary, 'chunk_size', 500, 1, 10000, 'spelling.dictionary');
        $insertBatchSize = self::integer($dictionary, 'insert_batch_size', 500, 1, 5000, 'spelling.dictionary');

        $insertionCost = self::integer($costs, 'insertion', 1000, 1, 1000000, 'spelling.correction.costs');
        $deletionCost = self::integer($costs, 'deletion', 1000, 1, 1000000, 'spelling.correction.costs');
        $substitutionCost = self::integer($costs, 'substitution', 1000, 1, 1000000, 'spelling.correction.costs');
        $transpositionCost = self::integer($costs, 'transposition', 700, 1, 1000000, 'spelling.correction.costs');
        $adjacentKeySubstitutionCost = self::integer($costs, 'adjacent_key_substitution', 450, 1, 1000000, 'spelling.correction.costs');

        if ($transpositionCost > max($insertionCost + $deletionCost, $substitutionCost * 2)) {
            throw InvalidSpellingConfigurationException::forValue(
                'spelling.correction.costs.transposition',
                $transpositionCost,
                'must not exceed the cost of replacing or deleting and inserting two adjacent characters',
            );
        }

        if ($adjacentKeySubstitutionCost > $substitutionCost) {
            throw InvalidSpellingConfigurationException::forValue(
                'spelling.correction.costs.adjacent_key_substitution',
                $adjacentKeySubstitutionCost,
                'must not exceed the regular substitution cost',
            );
        }

        return new self(
            enabled: $enabled,
            connection: $connection,
            termsTable: $termsTable,
            deletesTable: $deletesTable,
            minimumTokenLength: $minimumTokenLength,
            maximumTokenLength: $maximumTokenLength,
            maximumEditDistance: $maximumEditDistance,
            twoEditDistanceMinimumLength: $twoEditDistanceMinimumLength,
            maximumCandidatesPerToken: $maximumCandidatesPerToken,
            maximumCandidateRowsPerToken: $maximumCandidateRowsPerToken,
            maximumCandidateRowsPerQuery: $maximumCandidateRowsPerQuery,
            maximumQueryVariants: $maximumQueryVariants,
            maximumTokensToInspect: $maximumTokensToInspect,
            maximumTokensToCorrect: $maximumTokensToCorrect,
            maximumDeleteKeysPerQueryToken: $maximumDeleteKeysPerQueryToken,
            maximumDeleteKeysPerQuery: $maximumDeleteKeysPerQuery,
            maximumDeletesPerDictionaryTerm: $maximumDeletesPerDictionaryTerm,
            minimumDocumentFrequency: $minimumDocumentFrequency,
            maximumDictionaryTerms: $maximumDictionaryTerms,
            buildChunkSize: $buildChunkSize,
            insertBatchSize: $insertBatchSize,
            insertionCost: $insertionCost,
            deletionCost: $deletionCost,
            substitutionCost: $substitutionCost,
            transpositionCost: $transpositionCost,
            adjacentKeySubstitutionCost: $adjacentKeySubstitutionCost,
            failWhenUnavailable: self::boolean($config, 'fail_when_unavailable', false),
            adjacentKeys: self::adjacentKeys($config['adjacent_keys'] ?? []),
            protectedTerms: self::protectedTerms($dictionary['protected_terms'] ?? []),
        );
    }

    public function editDistanceFor(string $token): int
    {
        $length = self::length($token);

        if ($length < $this->minimumTokenLength || $length > $this->maximumTokenLength) {
            return 0;
        }

        if ($this->maximumEditDistance === 1 || $length < $this->twoEditDistanceMinimumLength) {
            return 1;
        }

        return $this->maximumEditDistance;
    }

    /** @return list<string> */
    public function localeChain(string $locale): array
    {
        $locale = trim($locale);
        $chain = [$locale];
        $parts = preg_split('/[-_]/', $locale, 2);
        $language = is_array($parts) ? ($parts[0] ?? '') : '';

        if ($language !== '' && $language !== $locale) {
            $chain[] = $language;
        }

        return array_values(array_unique($chain));
    }

    public function substitutionCost(string $from, string $to, string $locale): int
    {
        if ($from === $to) {
            return 0;
        }

        foreach ($this->localeChain($locale) as $candidateLocale) {
            $neighbors = $this->adjacentKeys[$candidateLocale][$from] ?? [];
            if (in_array($to, $neighbors, true)) {
                return $this->adjacentKeySubstitutionCost;
            }
        }

        return $this->substitutionCost;
    }

    /** @return list<string> */
    public function protectedTermsFor(string $locale): array
    {
        $terms = $this->protectedTerms['*'] ?? [];
        foreach ($this->localeChain($locale) as $candidateLocale) {
            $terms = [...$terms, ...($this->protectedTerms[$candidateLocale] ?? [])];
        }

        return array_values(array_unique($terms));
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : count(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private static function array(array $source, string $key, string $prefix): array
    {
        $value = $source[$key] ?? [];
        if (! is_array($value)) {
            throw InvalidSpellingConfigurationException::forValue("{$prefix}.{$key}", $value, 'must be an array');
        }

        return $value;
    }

    /** @param  array<string, mixed>  $source */
    private static function boolean(array $source, string $key, bool $default): bool
    {
        $value = $source[$key] ?? $default;
        if (! is_bool($value)) {
            throw InvalidSpellingConfigurationException::forValue("spelling.{$key}", $value, 'must be a boolean');
        }

        return $value;
    }

    /** @param  array<string, mixed>  $source */
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

    private static function name(mixed $value, string $key): string
    {
        if (! is_string($value) || ! CanonicalConfigurationName::isValid($value)) {
            throw InvalidSpellingConfigurationException::forValue($key, $value, 'must be a safe non-empty string');
        }

        return $value;
    }

    private static function nullableName(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::name($value, $key);
    }

    private static function tableName(mixed $value, string $key): string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $value) !== 1) {
            throw InvalidSpellingConfigurationException::forValue(
                $key,
                $value,
                'must be a safe unqualified database identifier containing only ASCII letters, digits, and underscores',
            );
        }

        return $value;
    }

    /** @return array<string, array<string, list<string>>> */
    private static function adjacentKeys(mixed $value): array
    {
        if (! is_array($value)) {
            throw InvalidSpellingConfigurationException::forValue('spelling.adjacent_keys', $value, 'must be an array');
        }

        $validated = [];
        foreach ($value as $locale => $mapping) {
            if (! is_string($locale) || ! CanonicalConfigurationName::isValid($locale) || ! is_array($mapping)) {
                throw InvalidSpellingConfigurationException::forValue('spelling.adjacent_keys', $value, 'must map locale names to character-neighbor maps');
            }
            foreach ($mapping as $character => $neighbors) {
                if (! is_string($character) || self::length($character) !== 1 || ! is_array($neighbors) || ! array_is_list($neighbors)) {
                    throw InvalidSpellingConfigurationException::forValue("spelling.adjacent_keys.{$locale}", $mapping, 'must map one Unicode character to a list of one-character neighbors');
                }
                $validatedNeighbors = [];
                foreach ($neighbors as $neighbor) {
                    if (! is_string($neighbor) || self::length($neighbor) !== 1 || $neighbor === $character) {
                        throw InvalidSpellingConfigurationException::forValue("spelling.adjacent_keys.{$locale}.{$character}", $neighbors, 'must contain distinct one-character strings');
                    }
                    $validatedNeighbors[] = $neighbor;
                }
                $validated[$locale][$character] = array_values(array_unique($validatedNeighbors));
            }
        }

        return $validated;
    }

    /** @return array<string, list<string>> */
    private static function protectedTerms(mixed $value): array
    {
        if (! is_array($value)) {
            throw InvalidSpellingConfigurationException::forValue('spelling.dictionary.protected_terms', $value, 'must be an array');
        }

        $validated = [];
        foreach ($value as $locale => $terms) {
            $validLocale = is_string($locale)
                && ($locale === '*' || CanonicalConfigurationName::isValid($locale));
            $validTerms = is_array($terms) && array_is_list($terms);

            if ($validLocale === false || $validTerms === false) {
                throw InvalidSpellingConfigurationException::forValue('spelling.dictionary.protected_terms', $value, 'must map locale names or * to term lists');
            }
            foreach ($terms as $term) {
                if (! is_string($term) || trim($term) === '') {
                    throw InvalidSpellingConfigurationException::forValue("spelling.dictionary.protected_terms.{$locale}", $terms, 'must contain non-empty strings');
                }
                $validated[$locale][] = $term;
            }
            $validated[$locale] = array_values(array_unique($validated[$locale] ?? []));
        }

        return $validated;
    }
}
