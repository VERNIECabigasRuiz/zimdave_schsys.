<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** A lesson plan the actor may $attribute (LessonPlanPolicy::VIEW or EDIT). */
final readonly class GetLessonPlanCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $planId,
        public string $attribute,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'planId' => $this->planId, 'attribute' => $this->attribute];
    }
}
