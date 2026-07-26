<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Spelling\SpellingPolicy;
use Zarbinco\PersianSearch\Spelling\WeightedDamerauLevenshtein;
use Zarbinco\PersianSearch\Spelling\WeightedEditDistance;

final class WeightedDamerauLevenshteinTest extends TestCase
{
    public function test_unicode_insert_delete_substitute_and_transpose_are_measured(): void
    {
        $distance = new WeightedDamerauLevenshtein($this->policy());

        $this->assertSame([1, 1000], $this->values($distance->measure('پرتال', 'پرتقال', 'fa')));
        $this->assertSame([1, 1000], $this->values($distance->measure('پرتتقال', 'پرتقال', 'fa')));
        $this->assertSame([1, 1000], $this->values($distance->measure('پرتفال', 'پرتقال', 'fa')));
        $this->assertSame([1, 700], $this->values($distance->measure('پترقال', 'پرتقال', 'fa')));
        $this->assertSame([1, 700], $this->values($distance->measure('oragne', 'orange', 'en')));
    }

    public function test_configured_adjacent_key_substitution_is_cheaper(): void
    {
        $distance = new WeightedDamerauLevenshtein($this->policy([
            'en' => ['p' => ['o']],
        ]));

        $this->assertSame([1, 450], $this->values($distance->measure('prange', 'orange', 'en')));
    }

    /** @param array<string, array<string, list<string>>> $adjacent */
    private function policy(array $adjacent = []): SpellingPolicy
    {
        return SpellingPolicy::fromArray([
            'enabled' => true,
            'adjacent_keys' => $adjacent,
        ]);
    }

    /** @return array{int, int} */
    private function values(WeightedEditDistance $distance): array
    {
        return [$distance->edits, $distance->cost];
    }
}
