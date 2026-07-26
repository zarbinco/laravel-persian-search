<?php

namespace Zarbinco\PersianSearch\Query;

use Zarbinco\PersianSearch\Exceptions\InvalidQueryVariantConfigurationException;
use Zarbinco\PersianSearch\Search\QueryVariantSource;

final readonly class QueryVariantPolicy
{
    /** @param  array<string, mixed>  $priorities */
    public static function fromArray(mixed $maximumVariants, array $priorities): self
    {
        if (! is_int($maximumVariants) || $maximumVariants <= 0) {
            throw InvalidQueryVariantConfigurationException::forValue('variants.maximum_variants', $maximumVariants, 'must be greater than zero');
        }

        $values = [
            'original' => self::configuredPriority($priorities, 'original', 1000),
            'keyboard' => self::configuredPriority($priorities, 'keyboard', 800),
            'synonym' => self::configuredPriority($priorities, 'synonym', 600),
            'keyboard_synonym' => self::configuredPriority($priorities, 'keyboard_synonym', 400),
        ];

        if (! ($values['original'] > $values['keyboard']
            && $values['keyboard'] > $values['synonym']
            && $values['synonym'] > $values['keyboard_synonym'])) {
            throw InvalidQueryVariantConfigurationException::forValue(
                'variants.priorities',
                $priorities,
                'must keep original above keyboard, keyboard above synonym, and synonym above keyboard_synonym',
            );
        }

        [$compatibleSpelling, $compatibleKeyboardSpelling] = self::compatibleSpellingPriorities(
            $values['keyboard'],
            $values['synonym'],
        );
        $values['spelling'] = self::configuredPriority($priorities, 'spelling', $compatibleSpelling);
        $values['keyboard_spelling'] = self::configuredPriority(
            $priorities,
            'keyboard_spelling',
            $compatibleKeyboardSpelling,
        );

        if (! ($values['keyboard'] >= $values['spelling']
            && $values['spelling'] >= $values['keyboard_spelling']
            && $values['keyboard_spelling'] >= $values['synonym'])) {
            throw InvalidQueryVariantConfigurationException::forValue(
                'variants.priorities',
                $priorities,
                'must keep spelling provenance between keyboard and synonym provenance',
            );
        }

        $advancedDefaults = self::compatibleAdvancedPriorities(
            $values['keyboard_spelling'],
            $values['synonym'],
        );
        foreach ($advancedDefaults as $key => $default) {
            $values[$key] = self::configuredPriority($priorities, $key, $default);
        }

        if (! ($values['keyboard_spelling'] >= $values['phonetic']
            && $values['phonetic'] >= $values['split']
            && $values['split'] >= $values['merge']
            && $values['merge'] >= $values['keyboard_phonetic']
            && $values['keyboard_phonetic'] >= $values['keyboard_split']
            && $values['keyboard_split'] >= $values['keyboard_merge']
            && $values['keyboard_merge'] >= $values['synonym'])) {
            throw InvalidQueryVariantConfigurationException::forValue(
                'variants.priorities',
                $priorities,
                'must keep advanced correction provenance between keyboard spelling and synonym provenance',
            );
        }

        return new self($maximumVariants, $values);
    }

    /** @param  array<string, int>  $priorities */
    private function __construct(
        public int $maximumVariants,
        private array $priorities,
    ) {}

    public function priority(QueryVariantSource $source): int
    {
        return $this->priorities[$source->value];
    }

    /** @param  array<string, mixed>  $priorities */
    private static function configuredPriority(array $priorities, string $key, int $default): int
    {
        $value = $priorities[$key] ?? $default;
        if (! is_int($value) || $value < 0) {
            throw InvalidQueryVariantConfigurationException::forValue(
                'variants.priorities.'.$key,
                $value,
                'must be an integer zero or greater',
            );
        }

        return $value;
    }

    /** @return array{int, int} */
    private static function compatibleSpellingPriorities(int $keyboard, int $synonym): array
    {
        if ($keyboard >= 700 && $synonym <= 650) {
            return [700, 650];
        }

        $gap = $keyboard - $synonym;
        if ($gap >= 3) {
            $keyboardSpelling = $synonym + max(1, intdiv($gap, 3));
            $spelling = $synonym + max(2, intdiv($gap * 2, 3));

            return [min($keyboard - 1, $spelling), min($spelling - 1, $keyboardSpelling)];
        }

        if ($gap === 2) {
            return [$keyboard - 1, $synonym];
        }

        return [$keyboard, $synonym];
    }

    /**
     * @return array{
     *   phonetic: int,
     *   split: int,
     *   merge: int,
     *   keyboard_phonetic: int,
     *   keyboard_split: int,
     *   keyboard_merge: int
     * }
     */
    private static function compatibleAdvancedPriorities(int $upper, int $lower): array
    {
        $gap = max(0, $upper - $lower);
        $priority = static fn (int $numerator): int => $lower + intdiv(($gap * $numerator) + 6, 7);

        return [
            'phonetic' => $priority(6),
            'split' => $priority(5),
            'merge' => $priority(4),
            'keyboard_phonetic' => $priority(3),
            'keyboard_split' => $priority(2),
            'keyboard_merge' => $priority(1),
        ];
    }
}
