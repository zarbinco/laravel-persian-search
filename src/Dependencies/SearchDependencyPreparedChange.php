<?php

namespace Zarbinco\PersianSearch\Dependencies;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchDependencyPreparedChange
{
    /** @var list<non-empty-string> */
    public array $changedAttributes;

    /** @param list<mixed> $changedAttributes */
    public function __construct(
        public SearchDependencyOperation $operation,
        public string $connection,
        public SearchDependencyTargetCollection $beforeTargets,
        array $changedAttributes = [],
    ) {
        if (! CanonicalConfigurationName::isValid($connection)) {
            throw new InvalidArgumentException('A prepared search dependency change requires a canonical connection name.');
        }

        if (! in_array($operation, [SearchDependencyOperation::Created, SearchDependencyOperation::Updated, SearchDependencyOperation::Deleted], true)) {
            throw new InvalidArgumentException('The prepared search dependency operation is invalid.');
        }

        if ($operation === SearchDependencyOperation::Updated && $changedAttributes === []) {
            throw new InvalidArgumentException('A prepared update requires changed attributes.');
        }

        if ($operation !== SearchDependencyOperation::Updated && $changedAttributes !== []) {
            throw new InvalidArgumentException('Only a prepared update may carry changed attributes.');
        }

        if ($operation === SearchDependencyOperation::Created && count($beforeTargets) !== 0) {
            throw new InvalidArgumentException('A prepared create cannot carry before-state targets.');
        }

        foreach ($changedAttributes as $attribute) {
            if (! is_string($attribute) || ! CanonicalConfigurationName::isValid($attribute)) {
                throw new InvalidArgumentException('Prepared update attributes must be canonical strings.');
            }
        }

        $canonical = array_values(array_unique($changedAttributes));
        sort($canonical, SORT_STRING);

        if ($canonical !== $changedAttributes) {
            throw new InvalidArgumentException('Prepared update attributes must be sorted and unique.');
        }

        /** @var list<non-empty-string> $changedAttributes */
        $this->changedAttributes = $changedAttributes;
    }
}
