<?php

namespace Zarbinco\PersianSearch\Text;

use Zarbinco\PersianSearch\Contracts\SearchTokenizer;

final class UnicodeSearchTokenizer implements SearchTokenizer
{
    public function tokenize(string $normalizedText, string $locale): array
    {
        preg_match_all(
            "/[\p{L}\p{N}][\p{L}\p{M}\p{N}]*(?:['’][\p{L}\p{N}][\p{L}\p{M}\p{N}]*)*/u",
            $normalizedText,
            $matches,
        );

        $tokens = [];
        $seen = [];

        foreach ($matches[0] as $token) {
            if ($token === '' || isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $tokens[] = $token;
        }

        return $tokens;
    }
}
