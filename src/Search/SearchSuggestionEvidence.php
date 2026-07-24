<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;
use JsonSerializable;
use Zarbinco\PersianSearch\Ranking\SearchRankTier;

final readonly class SearchSuggestionEvidence implements JsonSerializable
{
    public function __construct(
        public int $originalResultCount,
        public int $suggestedResultCount,
        public int $resultGain,
        public int $ratioBasisPoints,
        public ?SearchRankTier $originalBestTier,
        public SearchRankTier $suggestedBestTier,
        public bool $candidateWindowWasExact,
        public SearchSuggestionReason $reason,
    ) {
        $expectedRatio = $this->originalResultCount === 0
            ? 0
            : ($this->suggestedResultCount > intdiv(PHP_INT_MAX, 10000)
                ? PHP_INT_MAX
                : intdiv($this->suggestedResultCount * 10000, $this->originalResultCount));

        if ($this->originalResultCount < 0 || $this->suggestedResultCount < 1
            || $this->resultGain !== $this->suggestedResultCount - $this->originalResultCount
            || $this->resultGain < 0
            || $this->ratioBasisPoints !== $expectedRatio
            || ($this->originalResultCount === 0) !== ($this->originalBestTier === null)
            || ($this->reason === SearchSuggestionReason::OriginalHadNoResults) !== ($this->originalResultCount === 0)) {
            throw new InvalidArgumentException('Search suggestion evidence counts are inconsistent.');
        }

        $reasonIsValid = match ($this->reason) {
            SearchSuggestionReason::OriginalHadNoResults => $this->originalResultCount === 0
                && $this->originalBestTier === null
                && $this->resultGain === $this->suggestedResultCount
                && $this->ratioBasisPoints === 0,
            SearchSuggestionReason::BetterSemanticTier => $this->originalResultCount > 0
                && $this->originalBestTier !== null
                && $this->suggestedResultCount >= $this->originalResultCount
                && $this->suggestedBestTier->precedence() < $this->originalBestTier->precedence(),
            SearchSuggestionReason::MaterialResultGain => $this->originalResultCount > 0
                && $this->originalBestTier !== null
                && $this->suggestedResultCount > $this->originalResultCount
                && $this->resultGain > 0
                && $this->ratioBasisPoints > 10000,
        };

        if (! $reasonIsValid) {
            throw new InvalidArgumentException('Search suggestion evidence reason is inconsistent.');
        }
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'original_result_count' => $this->originalResultCount,
            'suggested_result_count' => $this->suggestedResultCount,
            'result_gain' => $this->resultGain,
            'ratio_basis_points' => $this->ratioBasisPoints,
            'original_best_tier' => $this->originalBestTier?->value,
            'suggested_best_tier' => $this->suggestedBestTier->value,
            'candidate_window_was_exact' => $this->candidateWindowWasExact,
            'reason' => $this->reason->value,
        ];
    }

    /** @return array<string, bool|int|string|null> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
