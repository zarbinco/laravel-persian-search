<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchDriver;

final class SearchQueryBuilder
{
    /** @var list<string> */
    private array $sourceTypes = [];

    private ?string $locale = null;

    private string $partition;

    private int $limit;

    private int $offset = 0;

    private bool $expansionEnabled = true;

    public function __construct(
        private readonly mixed $query,
        private readonly SearchQueryProcessor $processor,
        private readonly SearchDriver $driver,
        private readonly QueryExpander $expander,
    ) {
        $this->limit = (int) config('persian-search.search.default_limit', 20);
        $this->partition = (string) config('persian-search.index.default_partition', 'default');
    }

    /** @param  string|array<int, mixed>  $sourceTypes */
    public function for(string|array $sourceTypes): self
    {
        return is_array($sourceTypes) ? $this->types($sourceTypes) : $this->type($sourceTypes);
    }

    public function type(string $sourceType): self
    {
        $this->sourceTypes = [$this->validateSourceType($sourceType)];

        return $this;
    }

    /** @param  array<int, mixed>  $sourceTypes */
    public function types(array $sourceTypes): self
    {
        $validated = [];

        foreach ($sourceTypes as $sourceType) {
            if (! is_string($sourceType)) {
                throw new InvalidArgumentException('Source types must be non-empty strings.');
            }

            $validated[] = $this->validateSourceType($sourceType);
        }

        $this->sourceTypes = array_values(array_unique($validated));

        return $this;
    }

    public function locale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function currentLocale(): self
    {
        $this->locale = app()->getLocale();

        return $this;
    }

    public function partition(string $partition): self
    {
        $partition = trim($partition);

        if ($partition === '') {
            throw new InvalidArgumentException('Search partition must not be empty.');
        }

        $this->partition = $partition;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = min(max(1, (int) config('persian-search.search.max_limit', 100)), max(1, $limit));

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    public function expand(bool $enabled = true): self
    {
        $this->expansionEnabled = $enabled;

        return $this;
    }

    public function withoutExpansion(): self
    {
        return $this->expand(false);
    }

    public function variants(): QueryVariantCollection
    {
        $processed = $this->processor->process($this->query, $this->processingLocale());

        return $this->expansionEnabled
            ? $this->expander->expand($processed)
            : $this->expander->original($processed);
    }

    public function results(): SearchResults
    {
        $query = $this->queryObject();

        if (! $query->processedQuery->isSearchable()) {
            return new SearchResults($query, $query->processedQuery, [], 0);
        }

        return $this->driver->search($query);
    }

    /** @return Collection<int, Model> */
    public function get(): Collection
    {
        $query = $this->queryObject();

        if (! $query->processedQuery->isSearchable()) {
            return collect();
        }

        return $this->driver->search($query)->models();
    }

    public function first(): ?Model
    {
        return $this->limit(1)->get()->first();
    }

    private function queryObject(): SearchQuery
    {
        $processed = $this->processor->process($this->query, $this->processingLocale());
        if (! $processed->isSearchable()) {
            $variants = new QueryVariantCollection(1);
        } else {
            $variants = $this->expansionEnabled
                ? $this->expander->expand($processed)
                : $this->expander->original($processed);
        }

        $query = new SearchQuery(
            original: $processed->sanitizedQuery,
            normalized: $processed->normalizedQuery,
            tokens: $processed->searchableTokens,
            sourceTypes: $this->sourceTypes,
            locale: $processed->locale,
            partition: $this->partition,
            limit: min(max(1, (int) config('persian-search.search.max_limit', 100)), max(1, $this->limit)),
            offset: $this->offset,
            processedQuery: $processed,
            variants: $variants,
        );

        return $query;
    }

    private function processingLocale(): ?string
    {
        if ($this->locale !== null) {
            return $this->locale;
        }

        try {
            return app()->getLocale();
        } catch (Throwable) {
            return null;
        }
    }

    private function validateSourceType(string $sourceType): string
    {
        $sourceType = trim($sourceType);

        if ($sourceType === '') {
            throw new InvalidArgumentException('Source type must not be empty.');
        }

        return $sourceType;
    }
}
