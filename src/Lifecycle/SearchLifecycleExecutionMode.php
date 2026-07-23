<?php

namespace Zarbinco\PersianSearch\Lifecycle;

enum SearchLifecycleExecutionMode: string
{
    case Sync = 'sync';
    case Queue = 'queue';
}
