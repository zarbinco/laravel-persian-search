<?php

namespace Zarbinco\PersianSearch\Facades;

use Illuminate\Support\Facades\Facade;
use Zarbinco\PersianSearch\PersianSearchManager;

/**
 * @method static string normalize(string $value)
 * @method static array<int, string> tokens(string $value)
 */
final class PersianSearch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PersianSearchManager::class;
    }
}
