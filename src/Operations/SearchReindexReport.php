<?php

namespace Zarbinco\PersianSearch\Operations;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SearchReindexReport implements JsonSerializable
{
    public function __construct(
        public string $mode,
        public bool $dryRun,
        public int $enumerators,
        public int $enumerated,
        public int $uniqueSources,
        public int $duplicates,
        public int $synchronized,
        public int $queued,
        public int $suppressed,
        public int $failed,
        public int $unprocessed = 0,
    ) {
        foreach (get_object_vars($this) as $key => $value) {
            if (is_int($value) && $value < 0) {
                throw new InvalidArgumentException("Search reindex report count [{$key}] must not be negative.");
            }
        }
        if (! in_array($this->mode, ['sync', 'queue'], true)
            || $this->enumerated !== $this->uniqueSources + $this->duplicates
            || ($this->dryRun && ($this->synchronized + $this->queued + $this->suppressed + $this->failed + $this->unprocessed) !== 0)
            || ($this->mode === 'sync' && ($this->queued !== 0 || $this->suppressed !== 0))
            || ($this->mode === 'queue' && $this->synchronized !== 0)) {
            throw new InvalidArgumentException('Search reindex report counts are inconsistent.');
        }
        $completed = $this->synchronized + $this->queued + $this->suppressed;
        if ((! $this->dryRun && $completed + $this->failed + $this->unprocessed !== $this->uniqueSources)
            || ($this->unprocessed > 0 && $this->failed === 0)
            || $this->failed > 1) {
            throw new InvalidArgumentException('Search reindex report progress is inconsistent.');
        }
    }

    public function status(): string
    {
        if ($this->failed === 0) {
            return 'success';
        }

        return ($this->synchronized + $this->queued + $this->suppressed) > 0
            ? 'partial_failure'
            : 'failed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status(),
            'mode' => $this->mode,
            'dry_run' => $this->dryRun,
            'enumerators' => $this->enumerators,
            'enumerated' => $this->enumerated,
            'unique_sources' => $this->uniqueSources,
            'duplicates' => $this->duplicates,
            'synchronized' => $this->synchronized,
            'queued' => $this->queued,
            'suppressed' => $this->suppressed,
            'failed' => $this->failed,
            'unprocessed' => $this->unprocessed,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
