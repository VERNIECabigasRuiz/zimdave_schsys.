<?php

namespace App\Service;

use App\Data\DepEdSubjects;
use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Subject;
use App\Repository\SubjectRepository;
use App\Repository\TeachingLoadRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates, changes and removes the subjects a school offers. Every write is refused
 * while the school's plan is blocked (inactive school or expired plan = view-only).
 */
class SubjectManager
{
    /** DepEd Order 10, s. 2024: every learning area meets daily. */
    private const DEPED_MEETINGS_PER_WEEK = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SubjectRepository $subjects,
        private readonly PlanLimits $planLimits,
        private readonly ActivityLogger $activityLogger,
        private readonly TeachingLoadRepository $loads,
    ) {
    }

    /** Why subjects of this school can't be changed now, or null. */
    public function refusal(School $school): ?string
    {
        return $this->planLimits->blockedReason($school);
    }

    /** Saves a new or edited (already validated) subject; components follow its SHS settings. */
    public function save(Subject $subject): void
    {
        $this->assertWritable($subject->getSchool());
        $subject->inheritFromParent();
        foreach ($subject->getComponents() as $component) {
            $component->inheritFromParent();
        }
        if ($subject->hasComponents()) {
            $subject->setMeetingsPerWeek(null);
        }
        $isNew = null === $subject->getId();
        if ($isNew) {
            $this->entityManager->persist($subject);
        }

        $this->activityLogger->log(($isNew ? 'Created subject ' : 'Updated subject ').$subject->getLabel());
        $this->entityManager->flush();
    }

    public function delete(Subject $subject): void
    {
        $this->assertWritable($subject->getSchool());
        if (0 < $loaded = $this->loads->countForSubject($subject)) {
            throw new InUseException(\sprintf('%s is loaded in %d %s.', $subject->getName(), $loaded, 1 === $loaded ? 'section' : 'sections'));
        }
        $count = $subject->getComponents()->count();
        $this->activityLogger->log('Deleted subject '.$subject->getLabel().($count > 0 ? \sprintf(' with %d components', $count) : ''));
        $this->entityManager->remove($subject);
        $this->entityManager->flush();
    }

    /** Adds DepEd's standard subjects for a grade that this year doesn't have yet. */
    public function addDepEdSet(School $school, SchoolYear $schoolYear, GradeLevel $grade): int
    {
        $this->assertWritable($school);
        $taken = new TakenSubjects($this->subjects->findForSchool($school, $schoolYear));
        $position = $this->subjects->nextPosition($school, $schoolYear, $grade);

        $count = 0;
        foreach (DepEdSubjects::forGrade($grade, $schoolYear) as $preset) {
            $subject = $this->add($taken, new Subject($school, $schoolYear), $grade, $preset['code'], $preset['name'], $position);
            if (null === $subject) {
                continue;
            }
            if ([] === $preset['components']) {
                $subject->setMeetingsPerWeek(self::DEPED_MEETINGS_PER_WEEK);
            }
            ++$position;
            ++$count;
            foreach ($preset['components'] as $i => $component) {
                if (null !== $this->add($taken, (new Subject($school, $schoolYear))->setParent($subject), $grade, $component['code'], $component['name'], $i + 1)) {
                    ++$count;
                }
            }
        }

        if ($count > 0) {
            $this->activityLogger->log(\sprintf('Added %d DepEd subjects for %s', $count, $grade->label()));
            $this->entityManager->flush();
        }

        return $count;
    }

    /**
     * Copies last year's subjects (with components) that this year doesn't have.
     *
     * @param list<GradeLevel>|null $grades only these grade levels (null = every grade the school offers)
     */
    public function bringBack(School $school, SchoolYear $from, SchoolYear $to, ?array $grades = null): int
    {
        $this->assertWritable($school);
        $taken = new TakenSubjects($this->subjects->findForSchool($school, $to));
        $offered = $school->getType()?->gradeLevels() ?? [];
        if (null !== $grades) {
            $offered = array_values(array_filter($offered, static fn (GradeLevel $g) => \in_array($g, $grades, true)));
        }

        $count = 0;
        foreach ($this->subjects->findBringBackCandidates($school, $from, $to) as $old) {
            if (!\in_array($old->getGradeLevel(), $offered, true)) {
                continue;
            }
            $copy = $this->add($taken, $this->copyShs($old, new Subject($school, $to))->setMeetingsPerWeek($old->getMeetingsPerWeek()), $old->getGradeLevel(), $old->getCode(), $old->getName(), $old->getPosition());
            if (null === $copy) {
                continue;
            }
            ++$count;
            foreach ($old->getComponents() as $component) {
                if (null !== $this->add($taken, $this->copyShs($old, (new Subject($school, $to))->setParent($copy))->setMeetingsPerWeek($component->getMeetingsPerWeek()), $old->getGradeLevel(), $component->getCode(), $component->getName(), $component->getPosition())) {
                    ++$count;
                }
            }
        }

        if ($count > 0) {
            $this->activityLogger->log(\sprintf('Brought back %d subjects to S.Y. %s for %s', $count, $to->getName(), $school->getName()));
            $this->entityManager->flush();
        }

        return $count;
    }

    /** Persists the subject unless its code or name is already taken; null when skipped. */
    private function add(TakenSubjects $taken, Subject $subject, GradeLevel $grade, string $code, string $name, ?int $position): ?Subject
    {
        $subject->setGradeLevel($grade)->setCode($code)->setName($name)->setPosition($position);
        if ($taken->has($subject)) {
            $subject->setParent(null);

            return null;
        }
        $taken->add($subject);
        $this->entityManager->persist($subject);

        return $subject;
    }

    private function copyShs(Subject $from, Subject $to): Subject
    {
        return $to->setSemester($from->getSemester())->setTrack($from->getTrack())->setStrand($from->getStrand());
    }

    private function assertWritable(School $school): void
    {
        if (null !== $refusal = $this->refusal($school)) {
            throw new PlanLimitException($refusal);
        }
    }
}
