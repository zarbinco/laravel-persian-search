<?php

namespace Zarbinco\PersianSearch\Operations;

use InvalidArgumentException;
use JsonSerializable;
use Zarbinco\PersianSearch\Contracts\SearchSourceEnumerator;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchOperationsPolicy implements JsonSerializable
{
    /** @var list<class-string<SearchSourceEnumerator>> */
    public array $enumerators;

    /** @param array<array-key, mixed> $enumerators */
    public function __construct(
        array $enumerators = [],
        public int $chunkSize = 500,
        public int $maximumSourcesPerRun = 100000,
        public ?string $lockStore = null,
        public string $lockKey = 'persian-search:maintenance',
        public int $lockSeconds = 3600,
        public int $doctorSampleSize = 100,
    ) {
        if (! array_is_list($enumerators)) {
            throw new InvalidArgumentException('Search operation enumerators must be a list.');
        }

        $seen = [];
        foreach ($enumerators as $class) {
            if (! is_string($class) || $class === '' || isset($seen[$class])) {
                throw new InvalidArgumentException('Search operation enumerator classes must be unique non-empty strings.');
            }
            $seen[$class] = true;
        }
        /** @var list<class-string<SearchSourceEnumerator>> $enumerators */
        $this->enumerators = $enumerators;

        foreach ([
            'chunk size' => [$this->chunkSize, 5000],
            'maximum sources per run' => [$this->maximumSourcesPerRun, 5000000],
            'lock seconds' => [$this->lockSeconds, 86400],
            'doctor sample size' => [$this->doctorSampleSize, 10000],
        ] as $name => [$value, $maximum]) {
            if ($value < 1 || $value > $maximum) {
                throw new InvalidArgumentException("Search operations {$name} must be between 1 and {$maximum}.");
            }
        }

        if (($this->lockStore !== null && ! CanonicalConfigurationName::isValid($this->lockStore))
            || ! CanonicalConfigurationName::isValid($this->lockKey)) {
            throw new InvalidArgumentException('Search operations lock names must be canonical.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enumerators' => $this->enumerators,
            'chunk_size' => $this->chunkSize,
            'maximum_sources_per_run' => $this->maximumSourcesPerRun,
            'lock_store' => $this->lockStore,
            'lock_key' => $this->lockKey,
            'lock_seconds' => $this->lockSeconds,
            'doctor_sample_size' => $this->doctorSampleSize,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
