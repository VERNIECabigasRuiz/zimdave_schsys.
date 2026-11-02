<?php

namespace App\Service;

use App\Entity\ClassPeriod;
use App\Entity\ScheduleEntry;
use App\Entity\School;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Entity\Weekday;
use App\Repository\ClassPeriodRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SubjectRepository;
use App\Repository\TeachingLoadRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Lock\LockFactory;

/**
 * Every write to a school's teaching loads and timetables goes through here. Each
 * change holds the school's lock while it checks for conflicts, saves and logs, so
 * two requests can't both pass the checks and then both save.
 */
class TimetableManager
{
    private const BUSY = 'Another change is being saved. Please try again.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $registry,
        private readonly TeachingLoadRepository $loads,
        private readonly ScheduleEntryRepository $entries,
        private readonly SubjectRepository $subjects,
        private readonly ClassPeriodRepository $periods,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly PlanLimits $planLimits,
        private readonly ActivityLogger $activityLogger,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * Creates, changes or (with null) deletes the teaching load of a subject in a section.
     * A new teacher must be free at every slot the load is already plotted in.
     *
     * @throws TimetableException|PlanLimitException
     */
    public function setTeacher(Section $section, Subject $subject, ?User $teacher): ?TeachingLoad
    {
        return $this->locked($section->getSchool(), function () use ($section, $subject, $teacher): ?TeachingLoad {
            if (null === $teacher) {
                // no subject checks: a load can always be cleared, even if its subject later got components
                $this->assertWritable($section->getSchool());
                $load = $this->loads->findOne($section, $subject);
                if (null === $load) {
                    return null;
                }
                if (0 < $this->entries->countForLoad($load)) {
                    throw new TimetableException(\sprintf('Remove %s from the timetable before clearing its teacher.', $subject->getName()));
                }
                $this->entityManager->remove($load);
                $this->activityLogger->log(\sprintf('Cleared teacher for %s in %s', $subject->getLabel(), $section->getLabel()));
                $this->entityManager->flush();

                return null;
            }

            $this->assertLoadable($section, $subject);
            $load = $this->loads->findOne($section, $subject);
            $school = $this->assignments->findSchoolOf($teacher, $section->getSchoolYear(), User::ROLE_TEACHER);
            if ($school?->getId() !== $section->getSchool()->getId()) {
                throw new TimetableException(\sprintf('%s is not a teacher of this school this school year.', $teacher->getFullName()));
            }

            if (null === $load) {
                $load = new TeachingLoad($section, $subject, $teacher);
                $this->entityManager->persist($load);
                $this->activityLogger->log(\sprintf('Set teacher %s for %s in %s', $teacher->getUsername(), $subject->getLabel(), $section->getLabel()));
                $this->entityManager->flush();

                return $load;
            }
            if ($load->getTeacher() === $teacher) {
                return $load;
            }

            // the saved meetings, not this request's copy of them, which another request may have changed
            $entries = $this->entries->findForLoad($load);
            $clashes = [];
            foreach ($entries as $entry) {
                $clash = $this->entries->findTeacherClash($teacher, $section->getSchoolYear(), $entry->getDay(), $entry->getPeriod(), $entry->getTerm(), $section);
                if (null !== $clash) {
                    $clashes[] = $this->teacherBusy($teacher, $clash);
                }
            }
            if ([] !== $clashes) {
                throw new TimetableException(implode(' ', $clashes), $clashes);
            }

            $load->setTeacher($teacher);
            foreach ($entries as $entry) {
                $entry->syncTeacher();
            }
            $this->activityLogger->log(\sprintf('Changed teacher to %s for %s in %s', $teacher->getUsername(), $subject->getLabel(), $section->getLabel()));
            $this->entityManager->flush();

            return $load;
        });
    }

    /**
     * Puts one meeting of the subject's load on a day and class period.
     *
     * @throws TimetableException|PlanLimitException
     */
    public function plot(Section $section, Subject $subject, Weekday $day, ClassPeriod $period): ScheduleEntry
    {
        return $this->locked($section->getSchool(), function () use ($section, $subject, $day, $period): ScheduleEntry {
            $this->assertLoadable($section, $subject);
            if ($period->getSchool()->getId() !== $section->getSchool()->getId()
                || $period->getSchoolYear()->getId() !== $section->getSchoolYear()->getId()) {
                throw new TimetableException("That period is not one of this school's class periods.");
            }
            if ($period->isBreak()) {
                throw new TimetableException(\sprintf('%s is a break, not a class period.', $period->getBreakLabel()));
            }

            $load = $this->loads->findOne($section, $subject);
            if (null === $load) {
                throw new TimetableException(\sprintf('Pick a teacher for %s first.', $subject->getName()));
            }
            $perWeek = $subject->getMeetingsPerWeek();
            if (null === $perWeek) {
                throw new TimetableException(\sprintf('Set meetings per week for %s on the Subjects page first.', $subject->getName()));
            }
            if ($this->entries->countForLoad($load) >= $perWeek) {
                throw new TimetableException(\sprintf('%s already has all %d meetings this week.', $subject->getName(), $perWeek));
            }

            $term = $subject->getSemester() ?? 0;
            $clash = $this->entries->findSectionClash($section, $day, $period, $term);
            if (null !== $clash) {
                throw new TimetableException(\sprintf('%s already has %s on %s.', $section->getLabel(), $clash->getLoad()->getSubject()->getName(), $this->slot($day, $period)));
            }
            $teacher = $load->getTeacher();
            $clash = $this->entries->findTeacherClash($teacher, $section->getSchoolYear(), $day, $period, $term, $section);
            if (null !== $clash) {
                throw new TimetableException($this->teacherBusy($teacher, $clash));
            }

            $entry = new ScheduleEntry($load, $day, $period);
            $this->entityManager->persist($entry);
            $load->getEntries()->add($entry);
            $this->activityLogger->log(\sprintf('Plotted %s (%s) in %s, %s', $subject->getName(), $teacher->getFullName(), $section->getLabel(), $this->slot($day, $period)));
            $this->entityManager->flush();

            return $entry;
        });
    }

    /**
     * @throws TimetableException|PlanLimitException
     */
    public function remove(ScheduleEntry $entry): void
    {
        $this->locked($entry->getSection()->getSchool(), function () use ($entry): void {
            $this->assertWritable($entry->getSection()->getSchool());
            $entry->getLoad()->getEntries()->removeElement($entry);
            $this->entityManager->remove($entry);
            $this->activityLogger->log(\sprintf('Removed %s from %s, %s', $entry->getLoad()->getSubject()->getName(), $entry->getSection()->getLabel(), $this->slot($entry->getDay(), $entry->getPeriod())));
            $this->entityManager->flush();
        });
    }

    /**
     * Runs $change while holding the school's timetable lock.
     *
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     */
    private function locked(School $school, callable $change): mixed
    {
        $lock = $this->lockFactory->createLock('school-timetable-'.$school->getId());
        if (!$lock->acquire()) {
            throw new TimetableException(self::BUSY);
        }

        try {
            return $change();
        } catch (UniqueConstraintViolationException) {
            // another request saved the same slot between our checks and our flush;
            // the failed flush closed the entity manager, so give the rest of the request a fresh one
            $this->registry->resetManager();

            throw new TimetableException(self::BUSY);
        } finally {
            $lock->release();
        }
    }

    /** The plan allows changes, and the subject is a loadable subject of the section's grade. */
    private function assertLoadable(Section $section, Subject $subject): void
    {
        $this->assertWritable($section->getSchool());
        $ids = array_map(static fn (Subject $s) => $s->getId(), $this->subjects->findForSection($section));
        if (!\in_array($subject->getId(), $ids, true)) {
            throw new TimetableException(\sprintf('%s is not a subject of %s.', $subject->getName(), $section->getLabel()));
        }
        if (!$subject->isLoadable()) {
            throw new TimetableException(\sprintf('%s has components. Pick teachers for its components instead.', $subject->getName()));
        }
    }

    private function assertWritable(School $school): void
    {
        if (null !== $refusal = $this->planLimits->blockedReason($school)) {
            throw new PlanLimitException($refusal);
        }
    }

    private function teacherBusy(User $teacher, ScheduleEntry $clash): string
    {
        return \sprintf(
            '%s already teaches %s in %s on %s.',
            $teacher->getFullName(),
            $clash->getLoad()->getSubject()->getName(),
            $clash->getSection()->getLabel(),
            $this->slot($clash->getDay(), $clash->getPeriod()),
        );
    }

    /** "Mon, Period 3" */
    private function slot(Weekday $day, ClassPeriod $period): string
    {
        return $day->short().', '.$this->periods->label($period);
    }
}
