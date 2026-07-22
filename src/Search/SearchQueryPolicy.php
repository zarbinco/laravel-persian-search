<?php

namespace Zarbinco\PersianSearch\Search;

use Zarbinco\PersianSearch\Exceptions\InvalidSearchQueryConfigurationException;

final readonly class SearchQueryPolicy
{
    public function __construct(
        public int $minimumLength = 2,
        public int $maximumLength = 200,
        public int $minimumTokenLength = 1,
        public int $maximumTokens = 20,
        public MaximumLengthPolicy $maximumLengthPolicy = MaximumLengthPolicy::Truncate,
    ) {
        if ($this->minimumLength < 0) {
            throw InvalidSearchQueryConfigurationException::forValue('minimum_length', $this->minimumLength, 'must be zero or greater');
        }

        if ($this->maximumLength <= 0) {
            throw InvalidSearchQueryConfigurationException::forValue('maximum_length', $this->maximumLength, 'must be greater than zero');
        }

        if ($this->minimumLength > $this->maximumLength) {
            throw InvalidSearchQueryConfigurationException::forValue('minimum_length', $this->minimumLength, 'must not exceed maximum_length');
        }

        if ($this->minimumTokenLength < 1) {
            throw InvalidSearchQueryConfigurationException::forValue('minimum_token_length', $this->minimumTokenLength, 'must be at least one');
        }

        if ($this->maximumTokens <= 0) {
            throw InvalidSearchQueryConfigurationException::forValue('maximum_tokens', $this->maximumTokens, 'must be greater than zero');
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $minimumLength = self::integer($values, 'minimum_length', 2);
        $maximumLength = self::integer($values, 'maximum_length', 200);
        $minimumTokenLength = self::integer($values, 'minimum_token_length', 1);
        $maximumTokens = self::integer($values, 'maximum_tokens', 20);
        $rawPolicy = $values['maximum_length_policy'] ?? 'truncate';

        if (! is_string($rawPolicy) || MaximumLengthPolicy::tryFrom($rawPolicy) === null) {
            throw InvalidSearchQueryConfigurationException::forValue(
                'maximum_length_policy',
                $rawPolicy,
                'must be either truncate or reject',
            );
        }

        return new self(
            minimumLength: $minimumLength,
            maximumLength: $maximumLength,
            minimumTokenLength: $minimumTokenLength,
            maximumTokens: $maximumTokens,
            maximumLengthPolicy: MaximumLengthPolicy::from($rawPolicy),
        );
    }

    /** @param array<string, mixed> $values */
    private static function integer(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? $default;

        if (! is_int($value)) {
            throw InvalidSearchQueryConfigurationException::forValue($key, $value, 'must be an integer');
        }

        return $value;
    }
}
