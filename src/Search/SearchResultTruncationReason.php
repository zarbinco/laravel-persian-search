<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;

enum SearchResultTruncationReason: string
{
    case PerVariantLimit = 'per_variant_limit';
    case GlobalCandidateLimit = 'global_candidate_limit';
    case UnexecutedVariants = 'unexecuted_variants';

    /** @return list<self> */
    public static function ordered(): array
    {
        return self::cases();
    }

    /**
     * @param  array<int, mixed>  $reasons
     * @return list<self>
     */
    public static function normalize(array $reasons): array
    {
        $requested = [];

        foreach ($reasons as $reason) {
            if (! $reason instanceof self) {
                throw new InvalidArgumentException('Search result truncation reasons must be typed enum values.');
            }

            $requested[$reason->value] = true;
        }

        return array_values(array_filter(
            self::ordered(),
            static fn (self $reason): bool => isset($requested[$reason->value]),
        ));
    }
}
