<?php

namespace Zarbinco\PersianSearch\Providers;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchDocumentSetException;
use Zarbinco\PersianSearch\Indexing\SearchDocument;

/** @implements IteratorAggregate<int, SearchDocument> */
final readonly class SearchDocumentSet implements Countable, IteratorAggregate
{
    /** @var list<SearchDocument> */
    private array $documents;

    /** @param iterable<mixed> $documents */
    public static function fromIterable(SearchSourceReference $reference, iterable $documents, string $providerKey): self
    {
        $validated = [];
        $identities = [];

        foreach ($documents as $document) {
            if (! $document instanceof SearchDocument) {
                throw InvalidSearchDocumentSetException::invalidValue($providerKey, $document);
            }

            foreach ([
                'source key' => [$document->sourceKey(), $reference->sourceKey],
                'source type' => [$document->sourceType, $reference->sourceType],
                'source ID' => [$document->sourceId, $reference->sourceId],
            ] as $field => [$actual, $expected]) {
                if ($actual !== $expected) {
                    throw InvalidSearchDocumentSetException::sourceMismatch($providerKey, $field);
                }
            }

            $identity = hash('sha256', implode("\0", [$document->partition(), $document->sourceKey(), $document->locale()]));

            if (isset($identities[$identity])) {
                throw InvalidSearchDocumentSetException::duplicateIdentity(
                    $providerKey,
                    $document->partition(),
                    $document->sourceKey(),
                    $document->locale(),
                );
            }

            $identities[$identity] = true;
            $validated[] = $document;
        }

        return new self($reference, $validated);
    }

    /** @param list<SearchDocument> $documents */
    private function __construct(public SearchSourceReference $reference, array $documents)
    {
        $this->documents = $documents;
    }

    /** @return list<SearchDocument> */
    public function all(): array
    {
        return $this->documents;
    }

    public function isEmpty(): bool
    {
        return $this->documents === [];
    }

    public function count(): int
    {
        return count($this->documents);
    }

    /** @return Traversable<int, SearchDocument> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->documents);
    }

    /** @return array{reference: array<string, mixed>, documents: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference->toArray(),
            'documents' => array_map(static fn (SearchDocument $document): array => $document->toArray(), $this->documents),
        ];
    }
}
