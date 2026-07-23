<?php

namespace Zarbinco\PersianSearch\Candidates;

use Illuminate\Contracts\Config\Repository;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchCandidateConfigurationException;

final readonly class SearchCandidatePolicyFactory
{
    public function __construct(private Repository $config) {}

    public function make(): SearchCandidatePolicy
    {
        return new SearchCandidatePolicy(
            $this->positiveInteger('persian-search.candidates.maximum_terms_per_variant', 10, 50),
            $this->positiveInteger('persian-search.candidates.per_variant_limit', 100, 5000),
            $this->positiveInteger('persian-search.candidates.maximum_candidates', 500, 20000),
        );
    }

    private function positiveInteger(string $key, int $default, int $maximum): int
    {
        $value = $this->config->get($key, $default);

        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw InvalidSearchCandidateConfigurationException::forKey($key, $maximum, $value);
        }

        return $value;
    }
}
