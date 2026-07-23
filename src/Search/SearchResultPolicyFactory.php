<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Contracts\Config\Repository;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchResultConfigurationException;

final readonly class SearchResultPolicyFactory
{
    public function __construct(private Repository $config) {}

    public function make(): SearchResultPolicy
    {
        $defaultPerPage = $this->integer('default_per_page', 15, 1000);
        $maximumPerPage = $this->integer('maximum_per_page', 100, 1000);
        $defaultPreviewLimit = $this->integer('default_preview_limit', 8, 500);
        $maximumPreviewLimit = $this->integer('maximum_preview_limit', 50, 500);
        $defaultPreviewPerType = $this->integer('default_preview_per_type', 2, 100);
        $maximumPreviewPerType = $this->integer('maximum_preview_per_type', 10, 100);
        $maximumGroups = $this->integer('maximum_groups', 50, 500);

        $this->notAbove($defaultPerPage, $maximumPerPage, 'default_per_page', 'maximum_per_page');
        $this->notAbove($defaultPreviewLimit, $maximumPreviewLimit, 'default_preview_limit', 'maximum_preview_limit');
        $this->notAbove($defaultPreviewPerType, $maximumPreviewPerType, 'default_preview_per_type', 'maximum_preview_per_type');

        return new SearchResultPolicy(
            $defaultPerPage,
            $maximumPerPage,
            $defaultPreviewLimit,
            $maximumPreviewLimit,
            $defaultPreviewPerType,
            $maximumPreviewPerType,
            $maximumGroups,
        );
    }

    private function integer(string $key, int $default, int $maximum): int
    {
        $value = $this->config->get("persian-search.results.{$key}", $default);

        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw InvalidSearchResultConfigurationException::forValue(
                "persian-search.results.{$key}",
                $value,
                "must be a positive integer no greater than {$maximum}",
            );
        }

        return $value;
    }

    private function notAbove(int $default, int $maximum, string $defaultKey, string $maximumKey): void
    {
        if ($default > $maximum) {
            throw InvalidSearchResultConfigurationException::forValue(
                "persian-search.results.{$defaultKey}",
                $default,
                "must not exceed {$maximumKey}",
            );
        }
    }
}
