<?php

namespace Zarbinco\PersianSearch\Candidates;

final readonly class SearchCandidatePolicy
{
    public function __construct(
        public int $maximumTermsPerVariant,
        public int $perVariantLimit,
        public int $maximumCandidates,
    ) {}
}
