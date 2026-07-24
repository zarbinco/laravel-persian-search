<?php

namespace Zarbinco\PersianSearch\Operations;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Throwable;
use Zarbinco\PersianSearch\Contracts\AuthoritativeSearchSourceEnumerator;
use Zarbinco\PersianSearch\Contracts\SearchSourceEnumerator;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchSourceEnumeratorException;
use Zarbinco\PersianSearch\Providers\ProviderKey;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;

final class SearchSourceEnumeratorRegistry
{
    /** @var list<SearchSourceEnumeratorRegistration>|null */
    private ?array $registrations = null;

    public function __construct(
        private readonly Container $container,
        private readonly SearchOperationsPolicy $policy,
        private readonly SearchDocumentProviderRegistry $providers,
    ) {}

    /** @return list<SearchSourceEnumeratorRegistration> */
    public function all(): array
    {
        if ($this->registrations !== null) {
            return $this->registrations;
        }

        $classes = [];
        $keys = [];
        $providerKeys = array_fill_keys($this->providers->keys(), true);
        $registrations = [];

        foreach ($this->policy->enumerators as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, SearchSourceEnumerator::class)) {
                throw InvalidSearchSourceEnumeratorException::forClass($class);
            }
            if (isset($classes[$class])) {
                throw InvalidSearchSourceEnumeratorException::duplicateClass($class);
            }
            $classes[$class] = true;

            try {
                $enumerator = $this->container->make($class);
            } catch (Throwable) {
                throw InvalidSearchSourceEnumeratorException::forClass($class);
            }
            if (! $enumerator instanceof SearchSourceEnumerator) {
                throw InvalidSearchSourceEnumeratorException::forClass($class);
            }

            $key = $this->stable($enumerator, $class, 'key');
            $providerKey = $this->stable($enumerator, $class, 'providerKey');
            $model = $enumerator->sourceModel();
            if ($model !== $enumerator->sourceModel()) {
                throw InvalidSearchSourceEnumeratorException::unstable($class, 'source model');
            }

            if (ProviderKey::forLookup($key) !== $key || ProviderKey::forLookup($providerKey) !== $providerKey) {
                throw InvalidSearchSourceEnumeratorException::forClass($class);
            }
            if (isset($keys[$key])) {
                throw InvalidSearchSourceEnumeratorException::duplicateKey($key);
            }
            if (! isset($providerKeys[$providerKey])) {
                throw InvalidSearchSourceEnumeratorException::unknownProvider($providerKey);
            }
            if ($model !== null && (! is_subclass_of($model, Model::class) || (new ReflectionClass($model))->isAbstract())) {
                throw InvalidSearchSourceEnumeratorException::forClass($class);
            }

            $keys[$key] = true;
            $registrations[] = new SearchSourceEnumeratorRegistration(
                $enumerator,
                $class,
                $key,
                $providerKey,
                $model,
                $enumerator instanceof AuthoritativeSearchSourceEnumerator,
            );
        }

        usort($registrations, static function ($left, $right): int {
            return strcmp($left->providerKey, $right->providerKey)
                ?: strcmp($left->key, $right->key)
                ?: strcmp($left->enumeratorClass, $right->enumeratorClass);
        });

        return $this->registrations = $registrations;
    }

    /** @param list<string> $enumeratorKeys
     * @param  list<string>  $providerKeys
     * @return list<SearchSourceEnumeratorRegistration>
     */
    public function selected(array $enumeratorKeys, array $providerKeys): array
    {
        $all = $this->all();
        $knownKeys = array_column($all, 'key');
        $knownProviders = $this->providers->keys();
        foreach ($enumeratorKeys as $key) {
            if (! in_array($key, $knownKeys, true)) {
                throw InvalidSearchSourceEnumeratorException::unknownKey($key);
            }
        }
        foreach ($providerKeys as $key) {
            if (! in_array($key, $knownProviders, true)) {
                throw InvalidSearchSourceEnumeratorException::unknownProvider($key);
            }
        }

        return array_values(array_filter($all, static fn ($registration): bool => ($enumeratorKeys === [] || in_array($registration->key, $enumeratorKeys, true))
            && ($providerKeys === [] || in_array($registration->providerKey, $providerKeys, true))
        ));
    }

    /** @param list<string> $providerKeys
     * @return list<SearchSourceEnumeratorRegistration>
     */
    public function authoritativeForProviders(array $providerKeys): array
    {
        return array_values(array_filter(
            $this->selected([], $providerKeys),
            static fn ($registration): bool => $registration->authoritative,
        ));
    }

    private function stable(SearchSourceEnumerator $enumerator, string $class, string $method): string
    {
        $first = $enumerator->{$method}();
        if ($first !== $enumerator->{$method}()) {
            throw InvalidSearchSourceEnumeratorException::unstable($class, $method);
        }

        return $first;
    }
}
