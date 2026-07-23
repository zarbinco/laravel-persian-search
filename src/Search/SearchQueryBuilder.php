<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchPaginationException;

final class SearchQueryBuilder
{
    /** @var list<string> */
    private array $sourceTypes = [];

    private ?string $locale = null;

    private string $partition;

    private int $limit;

    private int $offset = 0;

    private bool $expansionEnabled = true;

    private bool $limitExplicit = false;

    private bool $offsetExplicit = false;

    /** @var list<SearchFacetField> */
    private array $facetFields = [];

    public function __construct(
        private readonly mixed $query,
        private readonly SearchQueryProcessor $processor,
        private readonly SearchDriver $driver,
        private readonly QueryExpander $expander,
        private readonly SearchResultPolicy $resultPolicy,
        private readonly EmptySearchResultFactory $emptyResults,
    ) {
        $this->limit = $this->resultPolicy->defaultPerPage;
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
        if ($limit < 1 || $limit > $this->resultPolicy->maximumPerPage) {
            throw new InvalidArgumentException("Search result limit must be between 1 and {$this->resultPolicy->maximumPerPage}.");
        }

        $this->limit = $limit;
        $this->limitExplicit = true;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Search result offset must be zero or greater.');
        }

        $this->offset = $offset;
        $this->offsetExplicit = true;

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

        if (! $processed->isSearchable()) {
            return new QueryVariantCollection(1);
        }

        return $this->expansionEnabled
            ? $this->expander->expand($processed)
            : $this->expander->original($processed);
    }

    public function results(): SearchResults
    {
        $query = $this->queryObject();

        if ($query->isEmpty()) {
            return $this->emptyResults->results($query);
        }

        return $this->driver->search($query);
    }

    /** @return Collection<int, Model> */
    public function get(): Collection
    {
        return $this->results()->models();
    }

    public function first(): ?Model
    {
        return $this->limit(1)->get()->first();
    }

    /** @param array<int, mixed> $fields */
    public function facets(array $fields): self
    {
        $requested = [];

        foreach ($fields as $field) {
            if (is_string($field)) {
                $field = SearchFacetField::tryFrom($field)
                    ?? throw new InvalidArgumentException("Unsupported search facet field [{$field}].");
            }

            if (! $field instanceof SearchFacetField) {
                throw new InvalidArgumentException('Search facet fields must be SearchFacetField values or supported strings.');
            }

            $requested[$field->value] = true;
        }

        $this->facetFields = array_values(array_filter(
            SearchFacetField::cases(),
            static fn (SearchFacetField $field): bool => isset($requested[$field->value]),
        ));

        return $this;
    }

    public function paginate(?int $perPage = null, int $page = 1): SearchPage
    {
        if ($this->limitExplicit || $this->offsetExplicit) {
            throw new InvalidSearchPaginationException(
                'paginate() owns final result slicing and cannot be combined with explicit limit() or offset().',
            );
        }

        $perPage ??= $this->resultPolicy->defaultPerPage;

        if ($perPage < 1 || $perPage > $this->resultPolicy->maximumPerPage) {
            throw new InvalidSearchPaginationException(
                "Search per-page must be between 1 and {$this->resultPolicy->maximumPerPage}.",
            );
        }

        $query = $this->queryObject();
        $request = new SearchPaginationRequest($page, $perPage);

        return $query->isEmpty()
            ? $this->emptyResults->page($query, $request)
            : $this->driver->paginate($query, $request);
    }

    public function preview(?int $limit = null, ?int $perType = null): SearchPreview
    {
        $limit ??= $this->resultPolicy->defaultPreviewLimit;
        $perType ??= $this->resultPolicy->defaultPreviewPerType;

        if ($limit < 1 || $limit > $this->resultPolicy->maximumPreviewLimit) {
            throw new InvalidArgumentException(
                "Search preview limit must be between 1 and {$this->resultPolicy->maximumPreviewLimit}.",
            );
        }

        if ($perType < 1 || $perType > $this->resultPolicy->maximumPreviewPerType) {
            throw new InvalidArgumentException(
                "Search preview per-type limit must be between 1 and {$this->resultPolicy->maximumPreviewPerType}.",
            );
        }

        $query = $this->queryObject();

        return $query->isEmpty()
            ? $this->emptyResults->preview($query, $limit, $perType)
            : $this->driver->preview($query, $limit, $perType);
    }

    public function groupBySourceType(int $perGroupLimit = 3): SearchResultGroupCollection
    {
        if ($perGroupLimit < 1) {
            throw new InvalidArgumentException('Search per-group limit must be positive.');
        }

        $query = $this->queryObject();

        return $query->isEmpty()
            ? $this->emptyResults->groups()
            : $this->driver->groupBySourceType($query, $perGroupLimit);
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
            limit: $this->limit,
            offset: $this->offset,
            processedQuery: $processed,
            variants: $variants,
            facetFields: $this->facetFields,
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
