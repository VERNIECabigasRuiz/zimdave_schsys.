<?php

declare(strict_types=1);

namespace App\Learners\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The account may not do this to the learner or section. */
final class LearnersAccessDeniedException extends DomainException
{
    public const ERROR_CODE = 'LEARNERS_ACCESS_DENIED';

    public function __construct(string $message = 'Access Denied.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
