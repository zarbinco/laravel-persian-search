<?php

namespace Zarbinco\PersianSearch\Search;

enum MaximumLengthPolicy: string
{
    case Truncate = 'truncate';
    case Reject = 'reject';
}
