<?php

declare(strict_types=1);

namespace App\LessonPlans\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The account may not do this with the lesson plan. */
final class LessonPlanAccessDeniedException extends DomainException
{
    public const ERROR_CODE = 'LESSON_PLAN_ACCESS_DENIED';

    public function __construct(string $message = 'Access Denied.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
