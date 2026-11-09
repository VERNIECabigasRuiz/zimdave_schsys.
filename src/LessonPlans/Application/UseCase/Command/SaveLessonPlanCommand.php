<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The teacher saves their plan's text; every day keeps its date and holiday. */
final readonly class SaveLessonPlanCommand implements UseCase
{
    /** @param list<array<string, string|null>> $days */
    public function __construct(
        public int $actorId,
        public int $planId,
        public ?string $topic,
        public ?string $competencies,
        public ?string $classNotes,
        public ?string $references,
        public ?string $aiDeclaration,
        public array $days,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'planId' => $this->planId, 'days' => \count($this->days)];
    }
}
