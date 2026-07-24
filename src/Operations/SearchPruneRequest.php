<?php

namespace Zarbinco\PersianSearch\Operations;

use JsonSerializable;

final readonly class SearchPruneRequest implements JsonSerializable
{
    /** @var list<string> */
    public array $enumeratorKeys;

    /** @var list<string> */
    public array $providerKeys;

    /** @param list<string> $enumeratorKeys
     * @param  list<string>  $providerKeys
     */
    public function __construct(
        array $enumeratorKeys = [],
        array $providerKeys = [],
        public ?int $limit = null,
        public bool $execute = false,
    ) {
        $validated = new SearchReindexRequest($enumeratorKeys, $providerKeys, null, $this->limit, ! $this->execute);
        $this->enumeratorKeys = $validated->enumeratorKeys;
        $this->providerKeys = $validated->providerKeys;
    }

    /** @return list<string> */
    public function enumeratorKeys(): array
    {
        return $this->enumeratorKeys;
    }

    /** @return list<string> */
    public function providerKeys(): array
    {
        return $this->providerKeys;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enumerator_keys' => $this->enumeratorKeys(),
            'provider_keys' => $this->providerKeys(),
            'limit' => $this->limit,
            'execute' => $this->execute,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
