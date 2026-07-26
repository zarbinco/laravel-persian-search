<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;
use JsonSerializable;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchSuggestion implements JsonSerializable
{
    public function __construct(
        public string $query,
        public string $locale,
        public QueryVariantSource $source,
        public string $variantFingerprint,
        public SearchSuggestionEvidence $evidence,
    ) {
        if ($this->query === '' || ! CanonicalConfigurationName::isValid($this->locale)
            || ! $this->source->isSuggestionRoot() || $this->variantFingerprint === '') {
            throw new InvalidArgumentException('Search suggestion metadata is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'locale' => $this->locale,
            'source' => $this->source->value,
            'variant_fingerprint' => $this->variantFingerprint,
            'evidence' => $this->evidence->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
