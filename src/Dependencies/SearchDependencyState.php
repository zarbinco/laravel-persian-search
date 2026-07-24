<?php

namespace Zarbinco\PersianSearch\Dependencies;

enum SearchDependencyState: string
{
    case Before = 'before';
    case After = 'after';
}
