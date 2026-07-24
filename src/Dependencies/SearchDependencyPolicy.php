<?php

namespace Zarbinco\PersianSearch\Dependencies;

use Zarbinco\PersianSearch\Exceptions\InvalidSearchDependencyConfigurationException;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchDependencyPolicy
{
    public const HARD_MAXIMUM_SOURCES_PER_EVENT = 20000;

    /** @var list<non-empty-string> */
    public array $resolverClasses;

    /** @param array<array-key, mixed> $resolverClasses */
    public function __construct(
        public bool $enabled,
        public int $maximumSourcesPerEvent,
        array $resolverClasses = [],
    ) {
        if ($maximumSourcesPerEvent < 1 || $maximumSourcesPerEvent > self::HARD_MAXIMUM_SOURCES_PER_EVENT) {
            throw InvalidSearchDependencyConfigurationException::forKey(
                'maximum_sources_per_event',
                'an integer from 1 through '.self::HARD_MAXIMUM_SOURCES_PER_EVENT,
                $maximumSourcesPerEvent,
            );
        }

        if (! array_is_list($resolverClasses)) {
            throw InvalidSearchDependencyConfigurationException::forKey(
                'resolvers',
                'a list of resolver class strings',
                $resolverClasses,
            );
        }

        $seen = [];
        foreach ($resolverClasses as $resolverClass) {
            if (! is_string($resolverClass) || ! CanonicalConfigurationName::isValid($resolverClass)) {
                throw InvalidSearchDependencyConfigurationException::forKey(
                    'resolvers',
                    'a list of non-empty resolver class strings',
                    $resolverClass,
                );
            }

            if (isset($seen[$resolverClass])) {
                throw InvalidSearchDependencyConfigurationException::forKey(
                    'resolvers',
                    'a list without duplicate resolver classes',
                    $resolverClass,
                );
            }

            $seen[$resolverClass] = true;
        }

        /** @var list<non-empty-string> $resolverClasses */
        $this->resolverClasses = $resolverClasses;
    }

    /** @return array{enabled: bool, maximum_sources_per_event: int, resolvers: list<non-empty-string>} */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'maximum_sources_per_event' => $this->maximumSourcesPerEvent,
            'resolvers' => $this->resolverClasses,
        ];
    }
}
