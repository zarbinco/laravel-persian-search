<?php

namespace Zarbinco\PersianSearch\Spelling;

use Zarbinco\PersianSearch\Exceptions\InvalidSpellingConfigurationException;

final class SpellingPolicyFactory
{
    public function make(): SpellingPolicy
    {
        $config = config('persian-search.spelling', []);
        if (! is_array($config)) {
            throw InvalidSpellingConfigurationException::forValue('spelling', $config, 'must be an array');
        }

        return SpellingPolicy::fromArray($config);
    }
}
