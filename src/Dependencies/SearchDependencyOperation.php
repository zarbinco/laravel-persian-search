<?php

namespace Zarbinco\PersianSearch\Dependencies;

enum SearchDependencyOperation: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
}
