<?php

namespace Zarbinco\PersianSearch\Operations;

use InvalidArgumentException;
use JsonSerializable;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchSourceEnumerationContext implements JsonSerializable
{
    /** @var list<string> */
    public array $selectedEnumeratorKeys;

    /** @var list<string> */
    public array $selectedProviderKeys;

    /** @param array<array-key, mixed> $selectedEnumeratorKeys
     * @param  array<array-key, mixed>  $selectedProviderKeys
     */
    public function __construct(
        public int $chunkSize,
        public ?int $limit,
        array $selectedEnumeratorKeys,
        array $selectedProviderKeys,
        public bool $dryRun,
    ) {
        if ($this->chunkSize < 1 || ($this->limit !== null && $this->limit < 1)) {
            throw new InvalidArgumentException('Enumeration chunk size and limit must be positive.');
        }
        $this->validateKeys($selectedEnumeratorKeys);
        $this->validateKeys($selectedProviderKeys);
        /** @var list<string> $selectedEnumeratorKeys */
        $this->selectedEnumeratorKeys = $selectedEnumeratorKeys;
        /** @var list<string> $selectedProviderKeys */
        $this->selectedProviderKeys = $selectedProviderKeys;
    }

    /** @param array<array-key, mixed> $keys */
    private function validateKeys(array $keys): void
    {
        $copy = $keys;
        usort($copy, strcmp(...));
        if (! array_is_list($keys) || $copy !== $keys || count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException('Enumeration filters must be unique binary-ordered lists.');
        }
        foreach ($keys as $key) {
            if (! CanonicalConfigurationName::isValid($key)) {
                throw new InvalidArgumentException('Enumeration filters must be canonical.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'chunk_size' => $this->chunkSize,
            'limit' => $this->limit,
            'selected_enumerator_keys' => $this->selectedEnumeratorKeys,
            'selected_provider_keys' => $this->selectedProviderKeys,
            'dry_run' => $this->dryRun,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
