<?php

namespace Zarbinco\PersianSearch\Operations;

enum SearchDoctorCheckStatus: string
{
    case Passed = 'passed';
    case Warning = 'warning';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
