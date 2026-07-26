<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

enum ContextualDecision: string
{
    case None = 'none';
    case SuggestOnly = 'suggest_only';
    case AutoApplyAllowed = 'auto_apply_allowed';
}
