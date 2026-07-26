<?php

namespace Zarbinco\PersianSearch\Spelling;

use JsonSerializable;

final readonly class SpellingDictionaryStatus implements JsonSerializable
{
    /** @param array<string, int> $localeTermCounts */
    public function __construct(
        public bool $enabled,
        public ?string $connection,
        public string $termsTable,
        public string $deletesTable,
        public bool $termsTableExists,
        public bool $deletesTableExists,
        public int $terms,
        public int $deletes,
        public array $localeTermCounts,
        public ?string $lastBuiltAt,
        public ?string $latestDocumentIndexedAt,
        public bool $stale,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'connection' => $this->connection,
            'tables' => [
                'terms' => $this->termsTable,
                'deletes' => $this->deletesTable,
                'terms_exists' => $this->termsTableExists,
                'deletes_exists' => $this->deletesTableExists,
            ],
            'counts' => [
                'terms' => $this->terms,
                'deletes' => $this->deletes,
                'by_locale' => $this->localeTermCounts,
            ],
            'last_built_at' => $this->lastBuiltAt,
            'latest_document_indexed_at' => $this->latestDocumentIndexedAt,
            'stale' => $this->stale,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
