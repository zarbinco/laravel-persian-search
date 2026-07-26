<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use Illuminate\Contracts\Config\Repository;
use Zarbinco\PersianSearch\Exceptions\InvalidSpellingConfigurationException;

final readonly class ContextualCorrectionPolicyFactory
{
    public function __construct(private Repository $config) {}

    public function make(): ContextualCorrectionPolicy
    {
        $values = $this->config->get('persian-search.contextual', []);
        if (! is_array($values) || array_is_list($values)) {
            throw new InvalidSpellingConfigurationException('persian-search.contextual must be an associative array.');
        }

        return ContextualCorrectionPolicy::fromArray($values);
    }
}
