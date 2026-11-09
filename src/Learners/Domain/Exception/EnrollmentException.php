<?php

declare(strict_types=1);

namespace App\Learners\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/**
 * A learner change was refused for a reason other than the plan limits. The message is safe to show.
 */
final class EnrollmentException extends DomainException
{
    public const ERROR_CODE = 'ENROLLMENT_REFUSED';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
