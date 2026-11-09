<?php

declare(strict_types=1);

namespace App\LessonPlans\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** No such lesson plan, or class. */
final class LessonPlanNotFoundException extends DomainException
{
    public const ERROR_CODE = 'LESSON_PLAN_NOT_FOUND';

    public function __construct(string $message = 'Not Found')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
