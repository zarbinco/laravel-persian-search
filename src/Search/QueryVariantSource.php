<?php

namespace Zarbinco\PersianSearch\Search;

enum QueryVariantSource: string
{
    case Original = 'original';
    case Keyboard = 'keyboard';
    case Synonym = 'synonym';
    case KeyboardSynonym = 'keyboard_synonym';
}
