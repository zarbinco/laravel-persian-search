<?php

namespace Zarbinco\PersianSearch\Ranking;

use LogicException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchRankingConfigurationException;

final readonly class SearchRankingPolicy
{
    /** @var array<string, int> */
    public array $tierScores;

    /** @param array<string, mixed> $tierScores */
    public function __construct(array $tierScores)
    {
        $expected = array_map(static fn (SearchRankTier $tier): string => $tier->value, SearchRankTier::ordered());
        $actual = array_keys($tierScores);
        $missing = array_values(array_diff($expected, $actual));
        $unknown = array_values(array_diff($actual, $expected));

        if ($missing !== []) {
            throw InvalidSearchRankingConfigurationException::forValue(
                'persian-search.ranking.tier_scores',
                $missing,
                'is missing required tier scores',
            );
        }

        if ($unknown !== []) {
            throw InvalidSearchRankingConfigurationException::forValue(
                'persian-search.ranking.tier_scores',
                $unknown,
                'contains unknown tier scores',
            );
        }

        $validated = [];
        $previous = null;

        foreach (SearchRankTier::ordered() as $tier) {
            $value = $tierScores[$tier->value];

            if (! is_int($value) || $value < 1) {
                throw InvalidSearchRankingConfigurationException::forValue(
                    "persian-search.ranking.tier_scores.{$tier->value}",
                    $value,
                    'must be a positive integer',
                );
            }

            if ($previous !== null && $value >= $previous) {
                throw InvalidSearchRankingConfigurationException::forValue(
                    "persian-search.ranking.tier_scores.{$tier->value}",
                    $value,
                    'must be strictly lower than the preceding tier score',
                );
            }

            $validated[$tier->value] = $value;
            $previous = $value;
        }

        if (count(array_unique($validated)) !== count($validated)) {
            throw InvalidSearchRankingConfigurationException::forValue(
                'persian-search.ranking.tier_scores',
                $validated,
                'must contain unique scores',
            );
        }

        $this->tierScores = $validated;
    }

    public function scoreFor(SearchRankTier $tier): int
    {
        foreach ($this->tierScores as $key => $score) {
            if ($key === $tier->value) {
                return $score;
            }
        }

        throw new LogicException("Validated search ranking tier [{$tier->value}] is unavailable.");
    }

    /** @return array{tier_scores: array<string, int>} */
    public function toArray(): array
    {
        return ['tier_scores' => $this->tierScores];
    }
}
