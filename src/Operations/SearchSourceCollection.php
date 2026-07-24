<?php

namespace Zarbinco\PersianSearch\Operations;

use Zarbinco\PersianSearch\Exceptions\SearchDependencyTargetConflictException;
use Zarbinco\PersianSearch\Exceptions\SearchOperationSourceLimitExceededException;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocator;

final class SearchSourceCollection
{
    /** @var array<string, SearchSourceLocator> */
    private array $locators = [];

    private int $enumerated = 0;

    private int $duplicates = 0;

    public function __construct(private readonly int $maximum) {}

    public function add(mixed $value, string $providerKey): void
    {
        $this->enumerated++;
        if (! $value instanceof SearchSourceLocator) {
            throw new \InvalidArgumentException('Search source enumerators must yield typed source locators.');
        }
        if ($value->providerKey !== $providerKey) {
            throw new \InvalidArgumentException('Search source locator provider ownership does not match its enumerator.');
        }

        $key = $value->fingerprint();
        $existing = $this->locators[$key] ?? null;
        if ($existing instanceof SearchSourceLocator) {
            if (! hash_equals($existing->fallbackReference->fingerprint(), $value->fallbackReference->fingerprint())) {
                throw SearchDependencyTargetConflictException::forLocators($existing, $value);
            }
            $this->duplicates++;

            return;
        }
        if (count($this->locators) >= $this->maximum) {
            throw new SearchOperationSourceLimitExceededException($this->maximum);
        }
        $this->locators[$key] = $value;
    }

    /** @return list<SearchSourceLocator> */
    public function all(): array
    {
        $values = array_values($this->locators);
        usort($values, static fn ($left, $right): int => strcmp($left->fingerprint(), $right->fingerprint()));

        return $values;
    }

    public function enumerated(): int
    {
        return $this->enumerated;
    }

    public function duplicates(): int
    {
        return $this->duplicates;
    }

    public function count(): int
    {
        return count($this->locators);
    }
}
