<?php

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

interface SearchDocumentProvider
{
    public function key(): string;

    public function supports(mixed $source): bool;

    public function reference(mixed $source): SearchSourceReference;

    /** @return iterable<SearchDocument> */
    public function documents(mixed $source): iterable;
}
