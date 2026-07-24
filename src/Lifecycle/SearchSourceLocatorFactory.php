<?php

namespace Zarbinco\PersianSearch\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;

final readonly class SearchSourceLocatorFactory
{
    public function __construct(private SearchDocumentProviderRegistry $providers) {}

    public function forModel(Model $source, string $providerKey, ?string $partition = null): SearchSourceLocator
    {
        $provider = $this->providers->provider($providerKey);

        if (! $provider->supports($source)) {
            throw new InvalidArgumentException("Search document provider [{$providerKey}] does not support the source model.");
        }

        return new SearchSourceLocator(
            EloquentSearchSourceLocator::fromModel($source),
            $providerKey,
            $provider->reference($source),
            $partition ?? (string) config('persian-search.index.default_partition', 'default'),
        );
    }

    public function forSource(Model $source, ?string $partition = null): SearchSourceLocator
    {
        $provider = $this->providers->resolve($source);

        return new SearchSourceLocator(
            EloquentSearchSourceLocator::fromModel($source),
            $this->providers->keyFor($provider),
            $provider->reference($source),
            $partition ?? (string) config('persian-search.index.default_partition', 'default'),
        );
    }
}
