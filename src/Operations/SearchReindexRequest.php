<?php

namespace Zarbinco\PersianSearch\Operations;

use InvalidArgumentException;
use JsonSerializable;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleExecutionMode;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchReindexRequest implements JsonSerializable
{
    /** @var list<string> */
    public array $enumeratorKeys;

    /** @var list<string> */
    public array $providerKeys;

    /** @param array<array-key, mixed> $enumeratorKeys
     * @param  array<array-key, mixed>  $providerKeys
     */
    public function __construct(
        array $enumeratorKeys = [],
        array $providerKeys = [],
        public ?string $executionMode = null,
        public ?int $limit = null,
        public bool $dryRun = false,
    ) {
        $this->enumeratorKeys = self::keys($enumeratorKeys);
        $this->providerKeys = self::keys($providerKeys);
        if (($this->executionMode !== null && SearchLifecycleExecutionMode::tryFrom($this->executionMode) === null)
            || ($this->limit !== null && $this->limit < 1)) {
            throw new InvalidArgumentException('Invalid reindex execution mode or limit.');
        }
    }

    /** @param array<array-key, mixed> $keys
     * @return list<string>
     */
    private static function keys(array $keys): array
    {
        if (! array_is_list($keys)) {
            throw new InvalidArgumentException('Search operation filters must be lists.');
        }
        foreach ($keys as $key) {
            if (! is_string($key) || ! CanonicalConfigurationName::isValid($key)) {
                throw new InvalidArgumentException('Search operation filters must be canonical non-empty strings.');
            }
        }
        $keys = array_values(array_unique($keys));
        usort($keys, strcmp(...));

        return $keys;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enumerator_keys' => $this->enumeratorKeys,
            'provider_keys' => $this->providerKeys,
            'execution_mode' => $this->executionMode,
            'limit' => $this->limit,
            'dry_run' => $this->dryRun,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
