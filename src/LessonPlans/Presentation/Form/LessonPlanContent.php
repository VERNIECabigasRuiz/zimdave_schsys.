<?php

declare(strict_types=1);

namespace App\LessonPlans\Presentation\Form;

use App\LessonPlans\Domain\Model\LessonPlan;
use Symfony\Component\Validator\Constraints as Assert;

/** A lesson plan's text as the teacher edits it; every day keeps its date and holiday. */
final class LessonPlanContent
{
    /** @param array<int, array<string, string|null>> $days by position in the week */
    public function __construct(
        #[Assert\Length(max: 200)]
        public ?string $topic = '',
        public ?string $competencies = '',
        public ?string $classNotes = null,
        public array $days = [],
        public ?string $references = null,
        public ?string $aiDeclaration = null,
    ) {
    }

    public static function fromPlan(LessonPlan $plan): self
    {
        return new self(
            $plan->getTopic(),
            $plan->getCompetencies(),
            $plan->getClassNotes(),
            $plan->getDays(),
            $plan->getReferences(),
            $plan->getAiDeclaration(),
        );
    }
}
