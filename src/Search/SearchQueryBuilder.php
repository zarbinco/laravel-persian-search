<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Contracts\SearchNormalizer;

final class SearchQueryBuilder
{
    /**
     * @var list<class-string>
     */
    private array $searchableTypes = [];

    private ?string $locale = null;

    private int $limit;

    private int $offset = 0;

    public function __construct(
        private readonly string $query,
        private readonly SearchNormalizer $normalizer,
        private readonly SearchDriver $driver,
    ) {
        $this->limit = (int) config('persian-search.search.default_limit', 20);
    }

    /**
     * @param  class-string|array<int, mixed>  $searchableTypes
     */
    public function for(string|array $searchableTypes): self
    {
        return is_array($searchableTypes)
            ? $this->types($searchableTypes)
            : $this->type($searchableTypes);
    }

    /**
     * @param  class-string  $searchableType
     */
    public function type(string $searchableType): self
    {
        $this->searchableTypes = [$this->validateSearchableType($searchableType)];

        return $this;
    }

    /**
     * @param  array<int, mixed>  $searchableTypes
     */
    public function types(array $searchableTypes): self
    {
        $types = [];

        foreach ($searchableTypes as $searchableType) {
            if (! is_string($searchableType)) {
                throw new InvalidArgumentException('Searchable types must be class strings.');
            }

            $types[] = $this->validateSearchableType($searchableType);
        }

        $this->searchableTypes = array_values(array_unique($types));

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

    public function limit(int $limit): self
    {
        $maxLimit = max(1, (int) config('persian-search.search.max_limit', 100));
        $this->limit = min($maxLimit, max(1, $limit));

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    public function results(): SearchResults
    {
        return $this->driver->search($this->queryObject(includeScores: true));
    }

    /**
     * @return Collection<int, Model>
     */
    public function get(): Collection
    {
        return $this->driver->search($this->queryObject(includeScores: false))->models();
    }

    public function first(): ?Model
    {
        return $this->limit(1)->get()->first();
    }

    private function queryObject(bool $includeScores): SearchQuery
    {
        $limit = min(
            max(1, (int) config('persian-search.search.max_limit', 100)),
            max(1, $this->limit),
        );

        return new SearchQuery(
            original: $this->query,
            normalized: $this->normalizer->normalize($this->query),
            tokens: $this->normalizer->tokens($this->query),
            searchableTypes: $this->searchableTypes,
            locale: $this->locale,
            limit: $limit,
            offset: $this->offset,
            includeScores: $includeScores,
        );
    }

    /**
     * @return class-string
     */
    private function validateSearchableType(string $searchableType): string
    {
        if (! class_exists($searchableType)) {
            throw new InvalidArgumentException("Searchable type [{$searchableType}] does not exist.");
        }

        if (! is_subclass_of($searchableType, Model::class)) {
            throw new InvalidArgumentException("Searchable type [{$searchableType}] must extend [".Model::class.'].');
        }

        return $searchableType;
    }
}
