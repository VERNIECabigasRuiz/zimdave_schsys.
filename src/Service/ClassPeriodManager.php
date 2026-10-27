<?php

namespace App\Service;

use App\Entity\ClassPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Repository\ClassPeriodRepository;
use App\Repository\ScheduleEntryRepository;
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
        private readonly ScheduleEntryRepository $entries,
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
        // Turning a period into a break would strand its classes
        if (!$isNew && $period->isBreak() && null === ($this->entityManager->getUnitOfWork()->getOriginalEntityData($period)['breakLabel'] ?? null)) {
            $this->assertNotInUse($period, true);
        }
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
        $this->assertNotInUse($period, false);
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

    /** @param bool $discardEdit drop the unsaved edit when refusing, so the entity shows what is stored */
    private function assertNotInUse(ClassPeriod $period, bool $discardEdit): void
    {
        if (0 === $sections = $this->entries->countForPeriod($period)) {
            return;
        }
        if ($discardEdit) {
            $this->entityManager->refresh($period);
        }
        throw new InUseException(\sprintf('%s has classes in %d %s. Remove them first.', $this->periods->label($period), $sections, 1 === $sections ? 'section' : 'sections'));
    }

    private function assertWritable(School $school): void
    {
        if (null !== $refusal = $this->refusal($school)) {
            throw new PlanLimitException($refusal);
        }
    }
}
