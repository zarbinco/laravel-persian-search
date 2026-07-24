<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Contracts\Config\Repository;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchLocaleBridgeConfigurationException;

final readonly class SearchLocaleBridgePolicyFactory
{
    public function __construct(private Repository $config) {}

    public function make(): SearchLocaleBridgePolicy
    {
        $values = $this->config->get('persian-search.locale_bridge', []);

        if (! is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new InvalidSearchLocaleBridgeConfigurationException(
                'persian-search.locale_bridge must be an associative array.',
            );
        }

        $enabled = $values['enabled'] ?? true;
        $batchSize = $values['batch_size'] ?? 200;

        if (! is_bool($enabled)) {
            throw new InvalidSearchLocaleBridgeConfigurationException(
                'persian-search.locale_bridge.enabled must be boolean.',
            );
        }

        if (! is_int($batchSize)) {
            throw new InvalidSearchLocaleBridgeConfigurationException(
                'persian-search.locale_bridge.batch_size must be an integer.',
            );
        }

        return new SearchLocaleBridgePolicy($enabled, $batchSize);
    }
}
