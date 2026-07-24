<?php

namespace Zarbinco\PersianSearch\Dependencies;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Zarbinco\PersianSearch\Contracts\SearchDependencyResolver;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchDependencyResolverException;
use Zarbinco\PersianSearch\Providers\ProviderKey;

final class SearchDependencyResolverRegistry
{
    /** @var list<SearchDependencyResolverRegistration>|null */
    private ?array $registrations = null;

    public function __construct(
        private readonly Container $container,
        private readonly SearchDependencyPolicy $policy,
    ) {}

    /** @return list<SearchDependencyResolverRegistration> */
    public function registrations(): array
    {
        if ($this->registrations !== null) {
            return $this->registrations;
        }

        $classes = [];
        $keys = [];
        $registrations = [];

        foreach ($this->policy->resolverClasses as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, SearchDependencyResolver::class)) {
                throw InvalidSearchDependencyResolverException::forClass($class);
            }

            if (isset($classes[$class])) {
                throw InvalidSearchDependencyResolverException::duplicateClass($class);
            }

            $classes[$class] = true;
            $resolver = $this->container->make($class);

            if (! $resolver instanceof SearchDependencyResolver) {
                throw InvalidSearchDependencyResolverException::forClass($class);
            }

            $firstKey = $resolver->key();
            $secondKey = $resolver->key();
            if ($firstKey !== $secondKey) {
                throw InvalidSearchDependencyResolverException::unstableKey($class);
            }

            $key = ProviderKey::forLookup($firstKey);
            if ($key !== $firstKey) {
                throw InvalidSearchDependencyResolverException::forClass($class);
            }

            if (isset($keys[$key])) {
                throw InvalidSearchDependencyResolverException::duplicateKey($key);
            }

            $firstModel = $resolver->dependencyModel();
            $secondModel = $resolver->dependencyModel();
            if ($firstModel !== $secondModel) {
                throw InvalidSearchDependencyResolverException::unstableDependencyModel($class);
            }

            if (! is_subclass_of($firstModel, Model::class) || (new ReflectionClass($firstModel))->isAbstract()) {
                throw InvalidSearchDependencyResolverException::forClass($class);
            }

            $keys[$key] = true;
            $registrations[] = new SearchDependencyResolverRegistration(
                $resolver,
                $class,
                $key,
                $firstModel,
            );
        }

        usort($registrations, self::compareRegistrations(...));

        return $this->registrations = $registrations;
    }

    /** @return list<SearchDependencyResolver> */
    public function all(): array
    {
        return array_map(
            static fn (SearchDependencyResolverRegistration $registration): SearchDependencyResolver => $registration->resolver,
            $this->registrations(),
        );
    }

    /** @param class-string<Model> $modelClass
     * @return list<SearchDependencyResolverRegistration>
     */
    public function forModelClass(string $modelClass): array
    {
        return array_values(array_filter(
            $this->registrations(),
            static fn (SearchDependencyResolverRegistration $registration): bool => $registration->dependencyModel === $modelClass,
        ));
    }

    /** @return list<SearchDependencyResolver> */
    public function forModel(Model $model): array
    {
        return array_map(
            static fn (SearchDependencyResolverRegistration $registration): SearchDependencyResolver => $registration->resolver,
            $this->forModelClass($model::class),
        );
    }

    /** @return list<class-string<Model>> */
    public function dependencyModels(): array
    {
        $models = array_values(array_unique(array_map(
            static fn (SearchDependencyResolverRegistration $registration): string => $registration->dependencyModel,
            $this->registrations(),
        )));
        sort($models, SORT_STRING);

        return $models;
    }

    private static function compareRegistrations(
        SearchDependencyResolverRegistration $left,
        SearchDependencyResolverRegistration $right,
    ): int {
        $model = strcmp($left->dependencyModel, $right->dependencyModel);

        if ($model !== 0) {
            return $model;
        }

        $key = strcmp($left->key, $right->key);

        if ($key !== 0) {
            return $key;
        }

        return strcmp($left->resolverClass, $right->resolverClass);
    }
}
