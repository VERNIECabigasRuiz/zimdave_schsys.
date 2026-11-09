<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Grading\Application\Service\GradeRecorder;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use Doctrine\ORM\EntityManagerInterface;

/** Calls the grade recorder as a use case would, saving after each call that succeeds. */
final class SavingGradeRecorder
{
    public function __construct(
        private readonly GradeRecorder $recorder,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param array<int, ?int> $values by enrollment id */
    public function saveSheet(TeachingLoad $load, GradingPeriod $period, array $values, User $by): int
    {
        $graded = $this->recorder->saveSheet($load, $period, $values, $by);
        $this->em->flush();

        return $graded;
    }

    public function openPeriod(School $school, GradingPeriod $period, User $by): void
    {
        $this->recorder->openPeriod($school, $period, $by);
        $this->em->flush();
    }

    public function closePeriod(School $school, GradingPeriod $period, User $by): void
    {
        $this->recorder->closePeriod($school, $period, $by);
        $this->em->flush();
    }

    public function lockPeriod(School $school, GradingPeriod $period, User $by): void
    {
        $this->recorder->lockPeriod($school, $period, $by);
        $this->em->flush();
    }

    public function unlockPeriod(School $school, GradingPeriod $period): void
    {
        $this->recorder->unlockPeriod($school, $period);
        $this->em->flush();
    }

    public function unlockClass(Section $section, Subject $subject, GradingPeriod $period, User $by): void
    {
        $this->recorder->unlockClass($section, $subject, $period, $by);
        $this->em->flush();
    }

    public function lockClass(Section $section, Subject $subject, GradingPeriod $period): void
    {
        $this->recorder->lockClass($section, $subject, $period);
        $this->em->flush();
    }
}
