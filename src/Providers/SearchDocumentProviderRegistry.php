<?php

namespace Zarbinco\PersianSearch\Providers;

use Illuminate\Contracts\Container\Container;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Exceptions\AmbiguousSearchDocumentProviderException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchDocumentProviderException;
use Zarbinco\PersianSearch\Exceptions\SearchDocumentProviderNotFoundException;

final class SearchDocumentProviderRegistry
{
    /** @var list<SearchDocumentProvider>|null */
    private ?array $providers = null;

    /** @var list<string>|null */
    private ?array $providerKeys = null;

    /** @param list<class-string> $configuredClasses */
    public function __construct(
        private readonly Container $container,
        private readonly array $configuredClasses,
        private readonly EloquentSearchDocumentProvider $fallback,
    ) {}

    /** @return list<SearchDocumentProvider> */
    public function all(): array
    {
        $this->initialize();

        if ($this->providers === null) {
            throw new \LogicException('Search document providers were not initialized.');
        }

        return $this->providers;
    }

    public function provider(string $key): SearchDocumentProvider
    {
        $key = ProviderKey::forLookup($key);

        $providerKeys = $this->keys();

        foreach ($this->all() as $index => $provider) {
            if ($providerKeys[$index] === $key) {
                return $provider;
            }
        }

        throw SearchDocumentProviderNotFoundException::forKey($key);
    }

    public function resolve(mixed $source): SearchDocumentProvider
    {
        $matches = [];
        $matchKeys = [];

        $this->initialize();
        $providers = $this->all();
        $providerKeys = $this->keys();
        $customCount = max(0, count($providers) - 1);

        for ($index = 0; $index < $customCount; $index++) {
            $provider = $providers[$index];

            if ($provider->supports($source)) {
                $matches[] = $provider;
                $matchKeys[] = $providerKeys[$index];
            }
        }

        if (count($matches) > 1) {
            throw AmbiguousSearchDocumentProviderException::forKeys($matchKeys);
        }

        if ($matches !== []) {
            return $matches[0];
        }

        $fallback = $providers[$customCount];

        if ($fallback->supports($source)) {
            return $fallback;
        }

        throw SearchDocumentProviderNotFoundException::forSource($source);
    }

    public function referenceFor(mixed $source): SearchSourceReference
    {
        return $this->resolve($source)->reference($source);
    }

    public function documentsFor(mixed $source): SearchDocumentSet
    {
        $provider = $this->resolve($source);
        $reference = $provider->reference($source);

        return SearchDocumentSet::fromIterable($reference, $provider->documents($source), $this->keyFor($provider));
    }

    public function documentsForProvider(string $key, mixed $source): SearchDocumentSet
    {
        $provider = $this->provider($key);

        if (! $provider->supports($source)) {
            throw SearchDocumentProviderNotFoundException::forSource($source);
        }

        return SearchDocumentSet::fromIterable(
            $provider->reference($source),
            $provider->documents($source),
            $this->keyFor($provider),
        );
    }

    private function initialize(): void
    {
        if ($this->providers !== null) {
            return;
        }

        $providers = [];
        $providerKeys = [];
        $classes = [];
        $fallbackKey = ProviderKey::fromProvider($this->fallback);
        $keys = [$fallbackKey => true];

        foreach ($this->configuredClasses as $class) {
            if (! class_exists($class)) {
                throw InvalidSearchDocumentProviderException::missingClass($class);
            }

            if (isset($classes[$class])) {
                throw InvalidSearchDocumentProviderException::duplicateClass($class);
            }

            $classes[$class] = true;

            if (! is_subclass_of($class, SearchDocumentProvider::class)) {
                throw InvalidSearchDocumentProviderException::invalidClass($class);
            }

            $provider = $this->container->make($class);

            if (! $provider instanceof SearchDocumentProvider) {
                throw InvalidSearchDocumentProviderException::invalidClass($class);
            }

            $key = ProviderKey::fromProvider($provider);

            if (isset($keys[$key])) {
                throw InvalidSearchDocumentProviderException::duplicateKey($key);
            }

            $keys[$key] = true;
            $providers[] = $provider;
            $providerKeys[] = $key;
        }

        $providers[] = $this->fallback;
        $providerKeys[] = $fallbackKey;
        $this->providers = $providers;
        $this->providerKeys = $providerKeys;
    }

    public function keyFor(SearchDocumentProvider $provider): string
    {
        $providerKeys = $this->keys();

        foreach ($this->all() as $index => $registered) {
            if ($registered === $provider) {
                return $providerKeys[$index];
            }
        }

        throw InvalidSearchDocumentProviderException::invalidClass($provider::class);
    }

    /** @return list<string> */
    public function keys(): array
    {
        $this->initialize();

        if ($this->providerKeys === null) {
            throw new \LogicException('Search document provider keys were not initialized.');
        }

        return $this->providerKeys;
    }
}
