<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Contextual\ContextualCandidateCollection;
use Zarbinco\PersianSearch\Contextual\CorrectionEvidenceCollection;

interface CorrectionEvidenceProvider
{
    public function evidenceFor(ContextualCandidateCollection $candidates): CorrectionEvidenceCollection;
}
