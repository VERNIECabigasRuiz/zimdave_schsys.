<?php

namespace App\Service;

use App\Entity\ClassPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Repository\ClassPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates, changes and removes the slots of a school's day. Every write is refused
 * while the school's plan is blocked (inactive school or expired plan = view-only).
 */
class ClassPeriodManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClassPeriodRepository $periods,
        private readonly PlanLimits $planLimits,
        private readonly ActivityLogger $activityLogger,
    ) {
    }

    /** Why the periods of this school can't be changed now, or null. */
    public function refusal(School $school): ?string
    {
        return $this->planLimits->blockedReason($school);
    }

    /** Saves a new or edited (already validated) period and re-sorts the day by start time. */
    public function save(ClassPeriod $period): void
    {
        $this->assertWritable($period->getSchool());
        $isNew = null === $period->getId();
        if ($isNew) {
            $this->entityManager->persist($period);
        }
        $this->entityManager->flush();
        $this->resort($period->getSchool(), $period->getSchoolYear());

        $this->activityLogger->log(($isNew ? 'Added class period ' : 'Updated class period ').$this->periods->label($period));
        $this->entityManager->flush();
    }

    public function delete(ClassPeriod $period): void
    {
        $this->assertWritable($period->getSchool());
        $this->activityLogger->log('Deleted class period '.$this->periods->label($period));
        $school = $period->getSchool();
        $schoolYear = $period->getSchoolYear();
        $this->entityManager->remove($period);
        $this->entityManager->flush();

        $this->resort($school, $schoolYear);
        $this->entityManager->flush();
    }

    /** position follows the start time, so lists and timetables read in school-day order */
    private function resort(School $school, SchoolYear $schoolYear): void
    {
        foreach ($this->periods->findForSchool($school, $schoolYear) as $i => $period) {
            $period->setPosition($i + 1);
        }
    }

    private function assertWritable(School $school): void
    {
        if (null !== $refusal = $this->refusal($school)) {
            throw new PlanLimitException($refusal);
        }
    }
}
