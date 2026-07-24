<?php

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Operations\SearchDoctorCheckResult;

interface SearchDoctorCheck
{
    public function key(): string;

    public function run(): SearchDoctorCheckResult;
}
