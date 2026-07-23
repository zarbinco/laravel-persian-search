<?php

namespace Zarbinco\PersianSearch\Search;

enum SearchFacetField: string
{
    case SourceType = 'source_type';
    case Partition = 'partition';
    case Locale = 'locale';
}
