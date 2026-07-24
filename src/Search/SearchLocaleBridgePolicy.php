<?php

namespace Zarbinco\PersianSearch\Search;

use Zarbinco\PersianSearch\Exceptions\InvalidSearchLocaleBridgeConfigurationException;

final readonly class SearchLocaleBridgePolicy
{
    public const MINIMUM_BATCH_SIZE = 1;

    public const MAXIMUM_BATCH_SIZE = 1000;

    /** @var int<self::MINIMUM_BATCH_SIZE, self::MAXIMUM_BATCH_SIZE> */
    public int $batchSize;

    public function __construct(public bool $enabled, int $batchSize)
    {
        if ($batchSize < self::MINIMUM_BATCH_SIZE || $batchSize > self::MAXIMUM_BATCH_SIZE) {
            throw new InvalidSearchLocaleBridgeConfigurationException(
                'persian-search.locale_bridge.batch_size must be between 1 and 1000.',
            );
        }

        $this->batchSize = $batchSize;
    }

    /** @return array{enabled: bool, batch_size: int} */
    public function toArray(): array
    {
        return ['enabled' => $this->enabled, 'batch_size' => $this->batchSize];
    }
}
