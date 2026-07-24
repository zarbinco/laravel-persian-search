<?php

namespace Zarbinco\PersianSearch\Operations;

enum SearchOperationExitCode: int
{
    case Success = 0;
    case Failed = 1;
    case Warning = 2;
    case InfrastructureFailure = 3;
    case LockUnavailable = 4;
    case ConfirmationRequired = 5;
}
