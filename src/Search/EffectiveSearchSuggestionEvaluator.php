<?php

namespace Zarbinco\PersianSearch\Search;

use RuntimeException;
use Zarbinco\PersianSearch\Ranking\SearchRank;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidateCollection;
use Zarbinco\PersianSearch\Ranking\SearchRankMatcher;

final readonly class EffectiveSearchSuggestionEvaluator
{
    public function __construct(
        private SearchSuggestionPolicy $policy,
        private SearchRankMatcher $matcher,
    ) {}

    public function evaluate(
        SearchRankedCandidateCollection $ranked,
        QueryVariantCollection $variants,
        bool $windowIsExact,
        string $originalQuery,
    ): ?SearchSuggestion {
        if (! $this->policy->enabled || ($this->policy->requireExactWindow && ! $windowIsExact)) {
            return null;
        }

        $variantMap = [];

        foreach ($variants as $variant) {
            $variantMap[$variant->fingerprint] = $variant;
        }

        $familiesByVariant = $this->resolveFamilies($variantMap);
        $suggestionRoots = array_filter(
            $variantMap,
            static fn (QueryVariant $variant): bool => $variant->source->isSuggestionRoot(),
        );

        if ($suggestionRoots === []) {
            return null;
        }

        /** @var array<string, array<string, true>> $familyCandidates */
        $familyCandidates = [];
        /** @var array<string, SearchRank> $familyBestRanks */
        $familyBestRanks = [];

        foreach ($ranked as $candidate) {
            $fields = $this->matcher->tokenize($candidate->candidate->document);
            $matchesByVariant = [];

            foreach ($candidate->candidate->matches as $match) {
                $matchesByVariant[$match->variant->fingerprint] ??= $match;
            }

            foreach ($matchesByVariant as $fingerprint => $match) {
                $family = $familiesByVariant[$fingerprint]
                    ?? throw new RuntimeException('Candidate evidence references an unavailable query variant.');

                $rank = $this->matcher->match($candidate->candidate->document, $match->variant, $fields);

                if ($rank === null) {
                    continue;
                }

                $identity = $candidate->candidate->identity();
                $familyCandidates[$family][$identity] = true;
                $best = $familyBestRanks[$family] ?? null;

                if ($best === null || SearchRank::compare($rank, $best) < 0) {
                    $familyBestRanks[$family] = $rank;
                }
            }
        }

        $originalCount = count($familyCandidates['original'] ?? []);
        $originalBest = $familyBestRanks['original'] ?? null;
        $eligible = [];

        foreach ($suggestionRoots as $variant) {
            if ($variant->query === $originalQuery) {
                continue;
            }

            $family = $variant->fingerprint;
            $count = count($familyCandidates[$family] ?? []);
            $best = $familyBestRanks[$family] ?? null;

            if ($count < $this->policy->minimumResults || ! $best instanceof SearchRank) {
                continue;
            }

            $ratio = $originalCount === 0 ? 0 : $this->ratio($count, $originalCount);
            $reason = null;

            if ($originalCount === 0) {
                $reason = SearchSuggestionReason::OriginalHadNoResults;
            } elseif ($originalBest instanceof SearchRank
                && $best->tier->precedence() < $originalBest->tier->precedence()
                && $count >= $originalCount) {
                $reason = SearchSuggestionReason::BetterSemanticTier;
            } elseif ($count >= $originalCount + $this->policy->minimumResultGain
                && $ratio >= $this->policy->minimumRatioBasisPoints) {
                $reason = SearchSuggestionReason::MaterialResultGain;
            }

            if ($reason === null) {
                continue;
            }

            $evidence = new SearchSuggestionEvidence(
                $originalCount,
                $count,
                $count - $originalCount,
                $ratio,
                $originalBest instanceof SearchRank ? $originalBest->tier : null,
                $best->tier,
                $windowIsExact,
                $reason,
            );
            $eligible[] = ['rule' => $reason, 'variant' => $variant, 'best' => $best, 'evidence' => $evidence];
        }

        usort($eligible, static function (array $left, array $right): int {
            $strength = static fn (SearchSuggestionReason $reason): int => match ($reason) {
                SearchSuggestionReason::OriginalHadNoResults => 0,
                SearchSuggestionReason::BetterSemanticTier => 1,
                SearchSuggestionReason::MaterialResultGain => 2,
            };

            return $strength($left['rule']) <=> $strength($right['rule'])
                ?: $left['best']->tier->precedence() <=> $right['best']->tier->precedence()
                ?: $right['evidence']->suggestedResultCount <=> $left['evidence']->suggestedResultCount
                ?: $right['evidence']->resultGain <=> $left['evidence']->resultGain
                ?: $right['variant']->priority <=> $left['variant']->priority
                ?: strcmp($left['variant']->fingerprint, $right['variant']->fingerprint);
        });
        $winner = $eligible[0] ?? null;

        return $winner === null ? null : new SearchSuggestion(
            $winner['variant']->query,
            $winner['variant']->locale,
            $winner['variant']->source,
            $winner['variant']->fingerprint,
            $winner['evidence'],
            $winner['variant']->contextualCorrection,
        );
    }

    /**
     * @param  array<string, QueryVariant>  $variants
     * @return array<string, string>
     */
    private function resolveFamilies(array $variants): array
    {
        $this->validateParentGraph($variants);
        $resolved = [];

        foreach ($variants as $fingerprint => $variant) {
            if (isset($resolved[$fingerprint])) {
                continue;
            }

            $path = [];
            $seen = [];
            $current = $variant;

            while (true) {
                if (isset($resolved[$current->fingerprint])) {
                    $family = $resolved[$current->fingerprint];

                    break;
                }

                if (isset($seen[$current->fingerprint])) {
                    throw new RuntimeException('Query variant lineage contains a cycle.');
                }

                $seen[$current->fingerprint] = true;
                $path[] = $current->fingerprint;

                if ($current->source === QueryVariantSource::Original) {
                    $family = 'original';

                    break;
                }

                if ($current->source->isSuggestionRoot()) {
                    $family = $current->fingerprint;

                    break;
                }

                if ($current->parentFingerprint === null || ! isset($variants[$current->parentFingerprint])) {
                    throw new RuntimeException('Query variant lineage parent is missing.');
                }

                $current = $variants[$current->parentFingerprint];
            }

            foreach ($path as $pathFingerprint) {
                $resolved[$pathFingerprint] = $family;
            }
        }

        return $resolved;
    }

    /** @param array<string, QueryVariant> $variants */
    private function validateParentGraph(array $variants): void
    {
        foreach ($variants as $variant) {
            $seen = [];
            $current = $variant;

            while ($current->parentFingerprint !== null) {
                if (isset($seen[$current->fingerprint])) {
                    throw new RuntimeException('Query variant lineage contains a cycle.');
                }

                $seen[$current->fingerprint] = true;
                $parent = $variants[$current->parentFingerprint] ?? null;

                if ($parent === null) {
                    throw new RuntimeException('Query variant lineage parent is missing.');
                }

                $current = $parent;
            }
        }
    }

    private function ratio(int $suggested, int $original): int
    {
        return $suggested > intdiv(PHP_INT_MAX, 10000)
            ? PHP_INT_MAX
            : intdiv($suggested * 10000, $original);
    }
}
