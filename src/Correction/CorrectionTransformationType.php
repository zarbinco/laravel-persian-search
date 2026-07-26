<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Correction;

enum CorrectionTransformationType: string
{
    case Phonetic = 'phonetic';
    case Split = 'split';
    case Merge = 'merge';
}
