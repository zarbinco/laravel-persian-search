<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

enum ContextualCandidateSource: string
{
    case Edit = 'edit';
    case Phonetic = 'phonetic';
    case Combined = 'combined';
}
