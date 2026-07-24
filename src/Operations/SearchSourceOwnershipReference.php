<?php

namespace Zarbinco\PersianSearch\Operations;

use Zarbinco\PersianSearch\Providers\ProviderKey;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchSourceOwnershipReference
{
    public string $providerKey;

    public function __construct(
        string $providerKey,
        public string $partition,
        public SearchSourceReference $source,
    ) {
        $canonical = ProviderKey::forLookup($providerKey);
        if ($canonical !== $providerKey || ! CanonicalConfigurationName::isValid($partition)) {
            throw new \InvalidArgumentException('Search source ownership metadata must be canonical.');
        }
        $this->providerKey = $canonical;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->providerKey,
            $this->partition,
            $this->source->sourceKey,
            $this->source->sourceType,
            $this->source->sourceId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function scopeFingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->providerKey,
            $this->partition,
            $this->source->sourceKey,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
