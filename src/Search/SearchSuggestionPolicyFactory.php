<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Contracts\Config\Repository;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchSuggestionConfigurationException;

final readonly class SearchSuggestionPolicyFactory
{
    public function __construct(private Repository $config) {}

    public function make(): SearchSuggestionPolicy
    {
        $values = $this->config->get('persian-search.suggestions', []);

        if (! is_array($values)) {
            throw new InvalidSearchSuggestionConfigurationException('Invalid suggestion configuration.');
        }

        $enabled = $values['enabled'] ?? true;
        $exact = $values['require_exact_window'] ?? true;
        $minimum = $values['minimum_results'] ?? 1;
        $gain = $values['minimum_result_gain'] ?? 2;
        $ratio = $values['minimum_ratio_basis_points'] ?? 15000;

        if (! is_bool($enabled) || ! is_bool($exact)
            || ! is_int($minimum) || ! is_int($gain) || ! is_int($ratio)) {
            throw new InvalidSearchSuggestionConfigurationException('Invalid suggestion configuration.');
        }

        return new SearchSuggestionPolicy($enabled, $exact, $minimum, $gain, $ratio);
    }
}
