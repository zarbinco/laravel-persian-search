<?php

namespace Zarbinco\PersianSearch\Dependencies;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchDependencyContext
{
    /** @var list<non-empty-string> */
    public array $changedAttributes;

    /** @param list<mixed> $changedAttributes */
    public function __construct(
        public Model $dependency,
        public SearchDependencyOperation $operation,
        public SearchDependencyState $state,
        public string $connection,
        array $changedAttributes = [],
    ) {
        if (! $dependency->exists || $dependency->getRelations() !== []) {
            throw new InvalidArgumentException('A dependency context requires an existing, relation-free detached snapshot.');
        }

        $validState = match ($operation) {
            SearchDependencyOperation::Created, SearchDependencyOperation::Restored => $state === SearchDependencyState::After,
            SearchDependencyOperation::Deleted => $state === SearchDependencyState::Before,
            SearchDependencyOperation::Updated => true,
        };

        if (! $validState
            || ! CanonicalConfigurationName::isValid($connection)
            || $dependency->getConnection()->getName() !== $connection) {
            throw new InvalidArgumentException('The search dependency context operation, state, or connection is invalid.');
        }

        foreach ($changedAttributes as $attribute) {
            if (! is_string($attribute) || ! CanonicalConfigurationName::isValid($attribute)) {
                throw new InvalidArgumentException('Search dependency changed attribute names must be canonical strings.');
            }
        }

        /** @var list<non-empty-string> $changedAttributes */
        $canonical = array_values(array_unique($changedAttributes));
        sort($canonical, SORT_STRING);

        if ($canonical !== $changedAttributes
            || ($operation === SearchDependencyOperation::Updated && $changedAttributes === [])
            || ($operation !== SearchDependencyOperation::Updated && $changedAttributes !== [])) {
            throw new InvalidArgumentException('Search dependency changed attributes must be a non-empty sorted unique update-only list.');
        }

        $this->changedAttributes = $changedAttributes;
    }

    /** @return array{dependency_model: class-string<Model>, operation: string, state: string, connection: string, changed_attributes: list<string>} */
    public function toArray(): array
    {
        return [
            'dependency_model' => $this->dependency::class,
            'operation' => $this->operation->value,
            'state' => $this->state->value,
            'connection' => $this->connection,
            'changed_attributes' => $this->changedAttributes,
        ];
    }
}
