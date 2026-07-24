<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;
use Throwable;
use Zarbinco\PersianSearch\Operations\SearchPruneReport;
use Zarbinco\PersianSearch\Operations\SearchReindexReport;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final class SearchOperationExecutionException extends RuntimeException
{
    public function __construct(
        public readonly SearchReindexReport|SearchPruneReport $partialReport,
        public readonly string $operationStage,
        string $safeMessage,
        ?Throwable $previous = null,
    ) {
        if (! CanonicalConfigurationName::isValid($operationStage)) {
            throw new \InvalidArgumentException('Search operation failure stage must be canonical.');
        }

        parent::__construct($safeMessage, previous: $previous);
    }
}
