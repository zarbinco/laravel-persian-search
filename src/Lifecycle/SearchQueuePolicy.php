<?php

namespace Zarbinco\PersianSearch\Lifecycle;

final readonly class SearchQueuePolicy
{
    /**
     * @param  list<int>  $backoff
     */
    public function __construct(
        public ?string $connection,
        public ?string $queue,
        public int $tries,
        public array $backoff,
        public int $timeout,
        public int $uniqueFor,
    ) {}
}
