<?php

namespace Zarbinco\PersianSearch\Dependencies;

use Illuminate\Contracts\Config\Repository;
use Zarbinco\PersianSearch\Contracts\SearchDependencyResolver;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchDependencyConfigurationException;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchDependencyPolicyFactory
{
    public function __construct(private Repository $config) {}

    public function make(): SearchDependencyPolicy
    {
        $dependencies = $this->configuration();
        $enabled = $dependencies['enabled'] ?? true;
        $maximum = $dependencies['maximum_sources_per_event'] ?? 1000;
        $resolvers = $dependencies['resolvers'] ?? [];

        if (! is_bool($enabled)) {
            throw InvalidSearchDependencyConfigurationException::forKey('enabled', 'a boolean', $enabled);
        }

        if (! is_int($maximum) || $maximum < 1 || $maximum > SearchDependencyPolicy::HARD_MAXIMUM_SOURCES_PER_EVENT) {
            throw InvalidSearchDependencyConfigurationException::forKey(
                'maximum_sources_per_event',
                'an integer from 1 through '.SearchDependencyPolicy::HARD_MAXIMUM_SOURCES_PER_EVENT,
                $maximum,
            );
        }

        if (! is_array($resolvers) || ! array_is_list($resolvers)) {
            throw InvalidSearchDependencyConfigurationException::forKey('resolvers', 'a list of resolver class strings', $resolvers);
        }

        $seen = [];
        foreach ($resolvers as $resolver) {
            if (! is_string($resolver) || ! CanonicalConfigurationName::isValid($resolver)) {
                throw InvalidSearchDependencyConfigurationException::forKey('resolvers', 'a list of resolver class strings', $resolver);
            }

            if (isset($seen[$resolver])) {
                throw InvalidSearchDependencyConfigurationException::forKey('resolvers', 'a list without duplicate classes', $resolver);
            }

            $seen[$resolver] = true;
        }

        /** @var list<class-string<SearchDependencyResolver>> $resolvers */
        return new SearchDependencyPolicy($enabled, $maximum, $resolvers);
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        $dependencies = $this->config->get('persian-search.dependencies', [
            'enabled' => true,
            'maximum_sources_per_event' => 1000,
            'resolvers' => [],
        ]);

        if (! is_array($dependencies) || ($dependencies !== [] && array_is_list($dependencies))) {
            throw InvalidSearchDependencyConfigurationException::forKey(
                'dependencies',
                'an associative configuration map',
                $dependencies,
            );
        }

        return $dependencies;
    }
}
