<?php

namespace Zarbinco\PersianSearch\Search;

enum SearchQueryStatus: string
{
    case Empty = 'empty';
    case TooShort = 'too_short';
    case TooLong = 'too_long';
    case PunctuationOnly = 'punctuation_only';
    case Ready = 'ready';
}
