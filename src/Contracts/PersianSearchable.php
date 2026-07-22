<?php

namespace Zarbinco\PersianSearch\Contracts;

interface PersianSearchable
{
    /**
     * Return fields whose values are aggregated into normalized document content.
     *
     * Supported examples:
     *
     * [
     *     'name',
     *     'description',
     * ]
     *
     * [
     *     'name' => 10,
     *     'brand.name' => 5,
     *     'description' => 1,
     * ]
     *
     * Weights are accepted for the Eloquent convenience API but are not persisted.
     *
     * @return array<int|string, string|int|float>
     */
    public function persianSearchableFields(): array;

    public function persianSearchTitle(): string;

    public function persianSearchLocale(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function persianSearchMetadata(): array;
}
