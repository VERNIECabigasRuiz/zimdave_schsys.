<?php

declare(strict_types=1);

namespace App\LessonPlans\Domain\Service\Signatory;

use App\Access\Domain\Model\User;

/** The position a person signs a lesson plan with, from the module that keeps their service record. */
interface SignatoryPositionsInterface
{
    /** Their current designation, or null when none is recorded. */
    public function positionOf(User $user): ?string;
}
