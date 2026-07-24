<?php

namespace Zarbinco\PersianSearch\Search;

enum SearchLocaleBridgeStatus: string
{
    case NotRequired = 'not_required';
    case Bridged = 'bridged';
    case CounterpartMissing = 'counterpart_missing';
    case Disabled = 'disabled';
}
