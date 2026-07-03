<?php

namespace Zarbinco\PersianSearch\Contracts;

interface PersianSearchable
{
    /**
     * Return searchable fields and optional weights.
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
