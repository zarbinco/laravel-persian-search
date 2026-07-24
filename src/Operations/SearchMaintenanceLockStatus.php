<?php

namespace Zarbinco\PersianSearch\Operations;

enum SearchMaintenanceLockStatus: string
{
    case Available = 'available';
    case Held = 'held';
    case Unknown = 'unknown';
}
