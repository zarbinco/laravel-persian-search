<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Correction;

use Zarbinco\PersianSearch\Exceptions\InvalidSpellingConfigurationException;

final class AdvancedCorrectionPolicyFactory
{
    public function make(): AdvancedCorrectionPolicy
    {
        $spelling = config('persian-search.spelling', []);
        if (! is_array($spelling)) {
            throw InvalidSpellingConfigurationException::forValue('spelling', $spelling, 'must be an array');
        }

        return AdvancedCorrectionPolicy::fromArray($spelling);
    }
}
