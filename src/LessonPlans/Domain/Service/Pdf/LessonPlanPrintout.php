<?php

declare(strict_types=1);

namespace App\LessonPlans\Domain\Service\Pdf;

use App\LessonPlans\Domain\Model\LessonPlan;
use App\Schools\Domain\Model\School;

/** What a lesson plan's PDF shows: the plan, its school, its bullet lists and who signs it. */
final readonly class LessonPlanPrintout
{
    /**
     * @param list<string>                             $competencies
     * @param list<string>                             $references
     * @param array{name: string, position: string}      $teacher
     * @param array{name: string, position: string}|null $principal
     */
    public function __construct(
        public LessonPlan $plan,
        public School $school,
        public array $competencies,
        public array $references,
        public array $teacher,
        public ?array $principal,
    ) {
    }

    /** "ILAW - Mathematics - ARTISTIC - 2026-11-30.pdf"; "/", "\" and "%" become "-" */
    public function fileName(): string
    {
        $load = $this->plan->getLoad();
        $name = \sprintf(
            'ILAW - %s - %s - %s.pdf',
            $load->getSubject()->getName(),
            $load->getSection()->getName(),
            $this->plan->getWeekStart()->format('Y-m-d'),
        );

        return (string) preg_replace('/[\/\\\\%]/', '-', $name);
    }
}
