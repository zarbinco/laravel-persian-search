<?php

namespace Zarbinco\PersianSearch\Indexing;

use Zarbinco\PersianSearch\Exceptions\InvalidSearchIndexingConfigurationException;

final readonly class SearchIndexingPolicy
{
    /** @var int<1, 10> */
    public int $transactionAttempts;

    public function __construct(int $transactionAttempts)
    {
        if ($transactionAttempts < 1 || $transactionAttempts > 10) {
            throw InvalidSearchIndexingConfigurationException::transactionAttempts($transactionAttempts);
        }

        $this->transactionAttempts = $transactionAttempts;
    }
}
