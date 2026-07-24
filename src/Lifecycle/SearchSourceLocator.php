<?php

namespace Zarbinco\PersianSearch\Lifecycle;

use Zarbinco\PersianSearch\Providers\ProviderKey;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchSourceLocator
{
    public string $providerKey;

    public function __construct(
        public EloquentSearchSourceLocator $source,
        string $providerKey,
        public SearchSourceReference $fallbackReference,
        public string $partition = 'default',
    ) {
        $canonical = ProviderKey::forLookup($providerKey);

        if ($canonical !== $providerKey) {
            throw new \InvalidArgumentException('Search source locator provider key must be canonical.');
        }
        if (! CanonicalConfigurationName::isValid($this->partition)) {
            throw new \InvalidArgumentException('Search source locator partition must be canonical.');
        }

        $this->providerKey = $canonical;
    }

    public function fingerprint(): string
    {
        return $this->synchronization()->routingFingerprint();
    }

    public function synchronization(): SearchLifecycleSynchronization
    {
        return new SearchLifecycleSynchronization($this->source, $this->fallbackReference, $this->providerKey);
    }

    /** @return array{source: array<string, mixed>, provider_key: string, fallback_reference: array<string, mixed>, partition: string, fingerprint: string} */
    public function toArray(): array
    {
        return [
            'source' => $this->source->toArray(),
            'provider_key' => $this->providerKey,
            'fallback_reference' => $this->fallbackReference->toArray(),
            'partition' => $this->partition,
            'fingerprint' => $this->fingerprint(),
        ];
    }
}
