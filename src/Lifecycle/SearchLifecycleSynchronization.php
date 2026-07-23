<?php

namespace Zarbinco\PersianSearch\Lifecycle;

use Zarbinco\PersianSearch\Providers\SearchSourceReference;

final readonly class SearchLifecycleSynchronization
{
    public function __construct(
        public EloquentSearchSourceLocator $locator,
        public SearchSourceReference $fallbackReference,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', $this->locator->fingerprint().'|'.$this->fallbackReference->fingerprint());
    }

    /** @return array{locator: array<string, mixed>, fallback_reference: array<string, mixed>, fingerprint: string} */
    public function toArray(): array
    {
        return [
            'locator' => $this->locator->toArray(),
            'fallback_reference' => $this->fallbackReference->toArray(),
            'fingerprint' => $this->fingerprint(),
        ];
    }
}
