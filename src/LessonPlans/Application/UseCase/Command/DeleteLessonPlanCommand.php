<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The teacher deletes their plan. */
final readonly class DeleteLessonPlanCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $planId,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'planId' => $this->planId];
    }
}
