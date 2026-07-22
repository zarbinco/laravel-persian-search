<?php

namespace Zarbinco\PersianSearch\Query;

use Zarbinco\PersianSearch\Exceptions\InvalidQueryVariantConfigurationException;
use Zarbinco\PersianSearch\Search\QueryVariantSource;

final readonly class QueryVariantPolicy
{
    /** @param array<string, mixed> $priorities */
    public static function fromArray(mixed $maximumVariants, array $priorities): self
    {
        if (! is_int($maximumVariants) || $maximumVariants <= 0) {
            throw InvalidQueryVariantConfigurationException::forValue('variants.maximum_variants', $maximumVariants, 'must be greater than zero');
        }

        $values = [];

        foreach (QueryVariantSource::cases() as $source) {
            $value = $priorities[$source->value] ?? null;

            if (! is_int($value) || $value < 0) {
                throw InvalidQueryVariantConfigurationException::forValue(
                    'variants.priorities.'.$source->value,
                    $value,
                    'must be an integer zero or greater',
                );
            }

            $values[$source->value] = $value;
        }

        if (! ($values['original'] > $values['keyboard']
            && $values['keyboard'] > $values['synonym']
            && $values['synonym'] > $values['keyboard_synonym'])) {
            throw InvalidQueryVariantConfigurationException::forValue(
                'variants.priorities',
                $priorities,
                'must strictly descend from original to keyboard to synonym to keyboard_synonym',
            );
        }

        return new self(
            maximumVariants: $maximumVariants,
            originalPriority: $values['original'],
            keyboardPriority: $values['keyboard'],
            synonymPriority: $values['synonym'],
            keyboardSynonymPriority: $values['keyboard_synonym'],
        );
    }

    private function __construct(
        public int $maximumVariants,
        public int $originalPriority,
        public int $keyboardPriority,
        public int $synonymPriority,
        public int $keyboardSynonymPriority,
    ) {}

    public function priority(QueryVariantSource $source): int
    {
        return match ($source) {
            QueryVariantSource::Original => $this->originalPriority,
            QueryVariantSource::Keyboard => $this->keyboardPriority,
            QueryVariantSource::Synonym => $this->synonymPriority,
            QueryVariantSource::KeyboardSynonym => $this->keyboardSynonymPriority,
        };
    }
}
