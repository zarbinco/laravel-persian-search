<?php

namespace Zarbinco\PersianSearch\Dependencies;

use Illuminate\Database\Eloquent\Model;

final readonly class SearchDependencyTargetResolver
{
    public function __construct(
        private SearchDependencyResolverRegistry $registry,
        private SearchDependencyPolicy $policy,
        private SearchDependencySnapshotFactory $snapshots,
    ) {}

    /** @param list<string> $changedAttributes */
    public function resolve(
        Model $snapshot,
        SearchDependencyOperation $operation,
        SearchDependencyState $state,
        array $changedAttributes = [],
    ): SearchDependencyTargetCollection {
        if (! $this->policy->enabled) {
            return new SearchDependencyTargetCollection([], $this->policy->maximumSourcesPerEvent);
        }

        $connection = $snapshot->getConnection()->getName();
        if (! is_string($connection)) {
            throw new \InvalidArgumentException('A search dependency snapshot requires a resolved connection name.');
        }

        $registrations = $this->registry->forModelClass($snapshot::class);

        return new SearchDependencyTargetCollection((function () use (
            $registrations,
            $snapshot,
            $operation,
            $state,
            $connection,
            $changedAttributes,
        ): iterable {
            foreach ($registrations as $registration) {
                $resolverSnapshot = $this->snapshots->copy($snapshot);
                $context = new SearchDependencyContext(
                    $resolverSnapshot,
                    $operation,
                    $state,
                    $connection,
                    $changedAttributes,
                );

                yield from $registration->resolver->resolve($context);
            }
        })(), $this->policy->maximumSourcesPerEvent);
    }
}
