<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Service;

use App\Access\Domain\Model\User;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\LessonPlans\Domain\Service\Pdf\LessonPlanPrintout;
use App\LessonPlans\Domain\Service\Signatory\SignatoryPositionsInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** Gathers what a lesson plan's PDF shows: the bullets, the teacher and the school's principal this school year. */
class LessonPlanPrinter
{
    public function __construct(
        private readonly SchoolDirectoryInterface $schools,
        private readonly SignatoryPositionsInterface $positions,
    ) {
    }

    public function printout(LessonPlan $plan): LessonPlanPrintout
    {
        $load = $plan->getLoad();
        $school = $load->getSection()->getSchool();
        $schoolYear = $this->schools->currentYear();
        $principal = null === $schoolYear ? null : $this->schools->principalOf($school, $schoolYear);

        return new LessonPlanPrintout(
            $plan,
            $school,
            LessonPlan::bullets($plan->getCompetencies()),
            LessonPlan::bullets($plan->getReferences()),
            $this->signatory($load->getTeacher(), 'Teacher'),
            null === $principal ? null : $this->signatory($principal, 'School Principal'),
        );
    }

    /** @return array{name: string, position: string} the person's name in capitals and their current position */
    private function signatory(User $user, string $fallback): array
    {
        return [
            'name' => mb_strtoupper($user->getFullName()),
            'position' => $this->positions->positionOf($user) ?? $fallback,
        ];
    }
}
