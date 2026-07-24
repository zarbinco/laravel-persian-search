<?php

namespace Zarbinco\PersianSearch\Operations;

use RuntimeException;
use Zarbinco\PersianSearch\Exceptions\SearchOperationSourceLimitExceededException;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocator;

final class SearchSourceOwnershipCollection
{
    /** @var array<string, SearchSourceOwnershipReference> */
    private array $references = [];

    /** @var array<string, string> */
    private array $scopes = [];

    public function __construct(private readonly int $maximum) {}

    public function add(mixed $value, string $providerKey): void
    {
        if (! $value instanceof SearchSourceLocator) {
            throw new \InvalidArgumentException('Search source enumerators must yield typed source locators.');
        }
        if ($value->providerKey !== $providerKey) {
            throw new \InvalidArgumentException('Search source locator provider ownership does not match its enumerator.');
        }

        $reference = new SearchSourceOwnershipReference(
            $value->providerKey,
            $value->partition,
            $value->fallbackReference,
        );
        $fingerprint = $reference->fingerprint();
        if (isset($this->references[$fingerprint])) {
            return;
        }
        $scope = $reference->scopeFingerprint();
        if (isset($this->scopes[$scope]) && ! hash_equals($this->scopes[$scope], $fingerprint)) {
            throw new RuntimeException('Conflicting Persian search source ownership was detected.');
        }
        if (count($this->references) >= $this->maximum) {
            throw new SearchOperationSourceLimitExceededException($this->maximum);
        }
        $this->scopes[$scope] = $fingerprint;
        $this->references[$fingerprint] = $reference;
    }

    /** @return list<SearchSourceOwnershipReference> */
    public function all(): array
    {
        ksort($this->references, SORT_STRING);

        return array_values($this->references);
    }
}
