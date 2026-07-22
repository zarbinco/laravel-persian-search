<?php

namespace Zarbinco\PersianSearch\Indexing;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class SearchDocument
{
    public SearchDocumentIdentity $identity;

    public string $sourceType;

    public ?string $sourceId;

    public string $documentHash;

    /** @var array<string|int, mixed> */
    public array $payload;

    public ?DateTimeImmutable $sourceUpdatedAt;

    /**
     * @param  array<string|int, mixed>  $payload
     */
    public function __construct(
        string $partition,
        string $sourceKey,
        string $sourceType,
        int|string|null $sourceId,
        ?string $locale,
        public ?string $title,
        public ?string $excerpt,
        public ?string $normalizedTitle,
        public ?string $normalizedExcerpt,
        public ?string $normalizedKeywords,
        public ?string $normalizedContent,
        array $payload = [],
        public int $priority = 0,
        public bool $isActive = true,
        ?DateTimeInterface $sourceUpdatedAt = null,
    ) {
        $this->identity = new SearchDocumentIdentity($partition, $sourceKey, $locale);
        $this->sourceType = trim($sourceType);
        $this->sourceId = is_int($sourceId) ? (string) $sourceId : $sourceId;

        if ($this->sourceType === '') {
            throw new InvalidArgumentException('Search document source type must not be empty.');
        }

        $safePayload = SearchDocumentHasher::jsonSafeValue($payload);
        $this->payload = is_array($safePayload) ? $safePayload : [];
        $this->sourceUpdatedAt = $sourceUpdatedAt === null
            ? null
            : DateTimeImmutable::createFromInterface($sourceUpdatedAt);
        $this->documentHash = (new SearchDocumentHasher)->hash($this);
    }

    public function partition(): string
    {
        return $this->identity->partition;
    }

    public function sourceKey(): string
    {
        return $this->identity->sourceKey;
    }

    public function locale(): string
    {
        return $this->identity->locale;
    }

    /**
     * @return array<string, mixed>
     */
    public function meaningfulData(): array
    {
        return [
            ...$this->identity->toArray(),
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'normalized_title' => $this->normalizedTitle,
            'normalized_excerpt' => $this->normalizedExcerpt,
            'normalized_keywords' => $this->normalizedKeywords,
            'normalized_content' => $this->normalizedContent,
            'payload' => $this->payload,
            'priority' => $this->priority,
            'is_active' => $this->isActive,
            'source_updated_at' => $this->sourceUpdatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->meaningfulData(),
            'document_hash' => $this->documentHash,
        ];
    }
}
