<?php

namespace Zarbinco\PersianSearch\Spelling;

final readonly class WeightedDamerauLevenshtein
{
    public function __construct(private SpellingPolicy $policy) {}

    public function measure(string $source, string $target, string $locale): WeightedEditDistance
    {
        if ($source === $target) {
            return new WeightedEditDistance(0, 0);
        }

        $left = $this->characters($source);
        $right = $this->characters($target);
        $leftCount = count($left);
        $rightCount = count($right);

        /** @var array<int, array<int, array{edits: int, cost: int}>> $matrix */
        $matrix = [];
        $matrix[0][0] = ['edits' => 0, 'cost' => 0];
        for ($i = 1; $i <= $leftCount; $i++) {
            $matrix[$i][0] = [
                'edits' => $i,
                'cost' => $matrix[$i - 1][0]['cost'] + $this->policy->deletionCost,
            ];
        }
        for ($j = 1; $j <= $rightCount; $j++) {
            $matrix[0][$j] = [
                'edits' => $j,
                'cost' => $matrix[0][$j - 1]['cost'] + $this->policy->insertionCost,
            ];
        }

        for ($i = 1; $i <= $leftCount; $i++) {
            for ($j = 1; $j <= $rightCount; $j++) {
                $same = $left[$i - 1] === $right[$j - 1];
                $substitutionCost = $same ? 0 : $this->policy->substitutionCost($left[$i - 1], $right[$j - 1], $locale);
                $substitution = [
                    'edits' => $matrix[$i - 1][$j - 1]['edits'] + ($same ? 0 : 1),
                    'cost' => $matrix[$i - 1][$j - 1]['cost'] + $substitutionCost,
                ];
                $deletion = [
                    'edits' => $matrix[$i - 1][$j]['edits'] + 1,
                    'cost' => $matrix[$i - 1][$j]['cost'] + $this->policy->deletionCost,
                ];
                $insertion = [
                    'edits' => $matrix[$i][$j - 1]['edits'] + 1,
                    'cost' => $matrix[$i][$j - 1]['cost'] + $this->policy->insertionCost,
                ];

                $best = $this->best([$substitution, $deletion, $insertion]);

                if ($i > 1 && $j > 1
                    && $left[$i - 1] === $right[$j - 2]
                    && $left[$i - 2] === $right[$j - 1]) {
                    $transposition = [
                        'edits' => $matrix[$i - 2][$j - 2]['edits'] + 1,
                        'cost' => $matrix[$i - 2][$j - 2]['cost'] + $this->policy->transpositionCost,
                    ];
                    $best = $this->best([$best, $transposition]);
                }

                $matrix[$i][$j] = $best;
            }
        }

        $result = $matrix[$leftCount][$rightCount];

        return new WeightedEditDistance($result['edits'], $result['cost']);
    }

    /** @param list<array{edits: int, cost: int}> $values
     * @return array{edits: int, cost: int}
     */
    private function best(array $values): array
    {
        usort($values, static fn (array $left, array $right): int => $left['cost'] <=> $right['cost'] ?: $left['edits'] <=> $right['edits']);

        return $values[0];
    }

    /** @return list<string> */
    private function characters(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
