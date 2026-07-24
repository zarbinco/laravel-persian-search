<?php

namespace Zarbinco\PersianSearch\Dependencies;

use Illuminate\Contracts\Container\Container;

final class SearchDependencyObserverRegistrar
{
    /** @var array<class-string, true> */
    private array $registered = [];

    public function __construct(
        private readonly SearchDependencyResolverRegistry $registry,
        private readonly Container $container,
        private readonly SearchDependencyPolicy $policy,
    ) {}

    public function register(): void
    {
        if (! $this->policy->enabled || $this->policy->resolverClasses === []) {
            return;
        }

        $this->registry->registrations();
        $observer = $this->container->make(SearchDependencyObserver::class);

        foreach ($this->registry->dependencyModels() as $model) {
            if (isset($this->registered[$model])) {
                continue;
            }

            $model::observe($observer);
            $this->registered[$model] = true;
        }
    }
}
