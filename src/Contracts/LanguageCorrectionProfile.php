<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Correction\PhoneticAlternative;

interface LanguageCorrectionProfile
{
    public function locale(): string;

    /** @return iterable<PhoneticAlternative> */
    public function phoneticAlternatives(string $token): iterable;

    /** @return list<string> */
    public function separators(): array;
}
