<?php

namespace Zarbinco\PersianSearch\Spelling;

final class SymmetricDeleteGenerator
{
    /** @return array<string, int> delete key => distance */
    public function generate(string $term, int $maximumDistance, int $maximumKeys): array
    {
        if ($term === '' || $maximumDistance < 0 || $maximumKeys < 1) {
            return [];
        }

        $results = [$term => 0];
        $frontier = [$term];

        for ($distance = 1; $distance <= $maximumDistance && count($results) < $maximumKeys; $distance++) {
            $next = [];
            foreach ($frontier as $candidate) {
                $characters = $this->characters($candidate);
                $count = count($characters);
                for ($index = 0; $index < $count; $index++) {
                    $deleted = implode('', [...array_slice($characters, 0, $index), ...array_slice($characters, $index + 1)]);
                    if ($deleted === '' || isset($results[$deleted])) {
                        continue;
                    }
                    $results[$deleted] = $distance;
                    $next[] = $deleted;
                    if (count($results) >= $maximumKeys) {
                        break 2;
                    }
                }
            }
            $frontier = $next;
            if ($frontier === []) {
                break;
            }
        }

        return $results;
    }

    /** @return list<string> */
    private function characters(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
