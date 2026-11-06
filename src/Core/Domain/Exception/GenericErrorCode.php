<?php

declare(strict_types=1);

namespace App\Core\Domain\Exception;

enum GenericErrorCode: string
{
    case InternalError = 'INTERNAL_ERROR';
    case ValidationFailed = 'VALIDATION_FAILED';
}
