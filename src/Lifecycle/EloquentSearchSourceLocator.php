<?php

namespace Zarbinco\PersianSearch\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Exceptions\InvalidEloquentSearchSourceLocatorException;
use Zarbinco\PersianSearch\Providers\ProviderKey;

final readonly class EloquentSearchSourceLocator
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public function __construct(
        public string $modelClass,
        public string $connection,
        public string $keyName,
        public string $keyValue,
    ) {
        if (! is_subclass_of($this->modelClass, Model::class)) {
            throw InvalidEloquentSearchSourceLocatorException::invalidModel($this->modelClass);
        }

        foreach (['connection', 'keyName', 'keyValue'] as $field) {
            $value = $this->{$field};

            if ($value === '' || trim($value) !== $value) {
                throw InvalidEloquentSearchSourceLocatorException::invalidField($field);
            }
        }
    }

    public static function fromModel(Model $model): self
    {
        $keyName = $model->getKeyName();
        $key = $model->getKey();
        $persistedKey = $model->getRawOriginal($keyName);

        if ($key === null || (! $model->exists && $persistedKey === null)) {
            throw InvalidEloquentSearchSourceLocatorException::unpersisted($model::class);
        }

        if (! is_int($key) && ! is_string($key)) {
            throw InvalidEloquentSearchSourceLocatorException::invalidField('keyValue');
        }

        $connection = $model->getConnection()->getName();

        if (! is_string($connection) || $connection === '') {
            throw InvalidEloquentSearchSourceLocatorException::invalidField('connection');
        }

        return new self($model::class, $connection, $keyName, (string) $key);
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            strlen($this->modelClass).':'.$this->modelClass,
            strlen($this->connection).':'.$this->connection,
            strlen($this->keyName).':'.$this->keyName,
            strlen($this->keyValue).':'.$this->keyValue,
        ]));
    }

    /** @return array{model_class: class-string<Model>, connection: string, key_name: string, key_value: string, fingerprint: string} */
    public function toArray(): array
    {
        return [
            'model_class' => $this->modelClass,
            'connection' => $this->connection,
            'key_name' => $this->keyName,
            'key_value' => $this->keyValue,
            'fingerprint' => $this->fingerprint(),
        ];
    }

    public function description(): string
    {
        return ProviderKey::describe($this->modelClass).'|'.ProviderKey::describe($this->connection).'|'.
            ProviderKey::describe($this->keyName).'|'.ProviderKey::describe($this->keyValue);
    }
}
