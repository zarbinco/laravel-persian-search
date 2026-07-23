<?php

namespace Zarbinco\PersianSearch\Lifecycle;

final readonly class SearchLifecyclePolicy
{
    public function __construct(
        public bool $automaticSync,
        public bool $afterCommit,
        public SearchLifecycleExecutionMode $execution,
        public bool $includeSoftDeleted,
    ) {}
}
