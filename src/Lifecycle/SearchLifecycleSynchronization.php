<?php

namespace Zarbinco\PersianSearch\Lifecycle;

use Zarbinco\PersianSearch\Providers\ProviderKey;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

final readonly class SearchLifecycleSynchronization
{
    public string $providerKey;

    public function __construct(
        public EloquentSearchSourceLocator $locator,
        public SearchSourceReference $fallbackReference,
        string $providerKey = 'eloquent',
    ) {
        $canonical = ProviderKey::forLookup($providerKey);

        if ($canonical !== $providerKey) {
            throw new \InvalidArgumentException('Search lifecycle provider key must be canonical.');
        }

        $this->providerKey = $canonical;
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->routingFingerprint().'|'.$this->fallbackReference->fingerprint());
    }

    public function routingFingerprint(): string
    {
        return hash(
            'sha256',
            strlen($this->providerKey).':'.$this->providerKey.'|'.$this->locator->fingerprint(),
        );
    }

    /** @return array{locator: array<string, mixed>, fallback_reference: array<string, mixed>, provider_key: string, routing_fingerprint: string, fingerprint: string} */
    public function toArray(): array
    {
        return [
            'locator' => $this->locator->toArray(),
            'fallback_reference' => $this->fallbackReference->toArray(),
            'provider_key' => $this->providerKey,
            'routing_fingerprint' => $this->routingFingerprint(),
            'fingerprint' => $this->fingerprint(),
        ];
    }
}
