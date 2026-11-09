<?php

declare(strict_types=1);

namespace App\Learners\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The learner isn't one the page manages. */
final class LearnerNotFoundException extends DomainException
{
    public const ERROR_CODE = 'LEARNER_NOT_FOUND';

    public function __construct(string $message = 'This learner is not enrolled here this school year.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
