<?php

namespace Zarbinco\PersianSearch\Search;

use Zarbinco\PersianSearch\Exceptions\InvalidSearchSuggestionConfigurationException;

final readonly class SearchSuggestionPolicy
{
    public const MAXIMUM_RESULTS = 20000;

    public const MAXIMUM_RESULT_GAIN = 20000;

    public const MAXIMUM_RATIO_BASIS_POINTS = 1000000;

    public function __construct(
        public bool $enabled,
        public bool $requireExactWindow,
        public int $minimumResults,
        public int $minimumResultGain,
        public int $minimumRatioBasisPoints,
    ) {
        if ($this->minimumResults < 1 || $this->minimumResults > self::MAXIMUM_RESULTS) {
            throw new InvalidSearchSuggestionConfigurationException(
                'persian-search.suggestions.minimum_results is outside its supported range.',
            );
        }

        if ($this->minimumResultGain < 1 || $this->minimumResultGain > self::MAXIMUM_RESULT_GAIN) {
            throw new InvalidSearchSuggestionConfigurationException(
                'persian-search.suggestions.minimum_result_gain is outside its supported range.',
            );
        }

        if ($this->minimumRatioBasisPoints < 1
            || $this->minimumRatioBasisPoints > self::MAXIMUM_RATIO_BASIS_POINTS) {
            throw new InvalidSearchSuggestionConfigurationException(
                'persian-search.suggestions.minimum_ratio_basis_points is outside its supported range.',
            );
        }
    }

    /** @return array<string, bool|int> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'require_exact_window' => $this->requireExactWindow,
            'minimum_results' => $this->minimumResults,
            'minimum_result_gain' => $this->minimumResultGain,
            'minimum_ratio_basis_points' => $this->minimumRatioBasisPoints,
        ];
    }
}
