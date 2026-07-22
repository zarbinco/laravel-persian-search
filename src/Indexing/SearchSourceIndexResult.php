<?php

namespace Zarbinco\PersianSearch\Indexing;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

final readonly class SearchSourceIndexResult
{
    public function __construct(
        public SearchSourceReference $reference,
        public int $incoming,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $deleted,
        public int $final,
    ) {
        foreach (['incoming', 'created', 'updated', 'unchanged', 'deleted', 'final'] as $field) {
            if ($this->{$field} < 0) {
                throw new InvalidArgumentException("Search source index result [{$field}] must not be negative.");
            }
        }

        if ($this->incoming !== $this->created + $this->updated + $this->unchanged) {
            throw new InvalidArgumentException('Incoming count must equal created, updated, and unchanged counts.');
        }

        if ($this->final !== $this->incoming) {
            throw new InvalidArgumentException('Final count must equal incoming count.');
        }
    }

    public function changed(): int
    {
        return $this->created + $this->updated + $this->deleted;
    }

    public function isNoOp(): bool
    {
        return $this->changed() === 0;
    }

    /** @return array{reference: array<string, mixed>, incoming: int, created: int, updated: int, unchanged: int, deleted: int, final: int, changed: int, is_no_op: bool} */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference->toArray(),
            'incoming' => $this->incoming,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'deleted' => $this->deleted,
            'final' => $this->final,
            'changed' => $this->changed(),
            'is_no_op' => $this->isNoOp(),
        ];
    }
}
