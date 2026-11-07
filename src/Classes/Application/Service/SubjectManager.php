<?php

declare(strict_types=1);

namespace App\Classes\Application\Service;

use App\Classes\Application\UseCase\Command\SubjectDetails;
use App\Classes\Domain\Exception\InUseException;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Classes\Domain\Model\Repository\TeachingLoadRepositoryInterface;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Service\Subject\DepEdSubjects;
use App\Classes\Domain\Service\Subject\TakenSubjects;
use App\Classes\Domain\Service\Usage\SubjectRecordsInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\PlanLimitException;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/**
 * Creates, changes and removes the subjects a school offers. Every write is refused
 * while the school's plan is blocked (inactive school or expired plan = view-only).
 */
class SubjectManager
{
    /** DepEd Order 10, s. 2024: every learning area meets daily. */
    private const DEPED_MEETINGS_PER_WEEK = 5;

    public function __construct(
        private readonly SubjectRepositoryInterface $subjects,
        private readonly SchoolDirectoryInterface $schools,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly TeachingLoadRepositoryInterface $loads,
        private readonly SubjectRecordsInterface $records,
    ) {
    }

    /** Why subjects of this school can't be changed now, or null. */
    public function refusal(School $school): ?string
    {
        return $this->schools->blockedReason($school);
    }

    /** Saves a new (already validated) subject; a component takes its parent's SHS settings. */
    public function create(Subject $subject): void
    {
        $this->assertWritable($subject->getSchool());
        $subject->inheritFromParent();
        $parent = $subject->getParent();
        if (null !== $parent && 0 < $loaded = $this->loads->countOwnForSubject($parent)) {
            $subject->setParent(null);

            throw $this->loadedRefusal($parent, $loaded);
        }

        $this->subjects->add($subject);
        $this->activityLogger->log('Created subject ' . $subject->getLabel());
    }

    /**
     * Applies an (already validated) edit; components follow its SHS settings. A loaded subject
     * keeps its grade level, semester and parent, and a loaded subject gets no components: either
     * change would leave its loads in a timetable they no longer fit. Such an edit is refused
     * before anything changes.
     */
    public function update(Subject $subject, SubjectDetails $details, ?Subject $parent): void
    {
        $this->assertWritable($subject->getSchool());
        $semester = null !== $parent ? $parent->getSemester() : $details->semester;
        $newParent = null !== $parent && $parent !== $subject->getParent();
        if ($newParent && 0 < $loaded = $this->loads->countOwnForSubject($parent)) {
            throw $this->loadedRefusal($parent, $loaded);
        }
        $changed = $details->gradeLevel !== $subject->getGradeLevel()
            || $semester !== $subject->getSemester()
            || $parent !== $subject->getParent();
        if ($changed && 0 < $loaded = $this->loads->countForSubject($subject)) {
            throw $this->loadedRefusal($subject, $loaded);
        }

        $subject->setGradeLevel($details->gradeLevel)
            ->setCode($details->code)
            ->setName($details->name)
            ->setParent($parent)
            ->setPosition($details->position)
            ->setMeetingsPerWeek($details->meetingsPerWeek)
            ->setSemester($details->semester)
            ->setTrack($details->track)
            ->setStrand($details->strand)
            ->inheritFromParent();
        foreach ($subject->getComponents() as $component) {
            $component->inheritFromParent();
        }
        if ($subject->hasComponents()) {
            $subject->setMeetingsPerWeek(null);
        }

        $this->activityLogger->log('Updated subject ' . $subject->getLabel());
    }

    public function delete(Subject $subject): void
    {
        $this->assertWritable($subject->getSchool());
        if (0 < $loaded = $this->loads->countForSubject($subject)) {
            throw new InUseException(\sprintf(
                '%s is loaded in %d %s.',
                $subject->getName(),
                $loaded,
                1 === $loaded ? 'section' : 'sections',
            ));
        }
        if (0 < $graded = $this->records->countGrades($subject)) {
            throw new InUseException(\sprintf(
                '%s has grades in %d %s.',
                $subject->getName(),
                $graded,
                1 === $graded ? "learner's record" : "learners' records",
            ));
        }
        if (0 < $this->records->countGradeChangeRequests($subject)) {
            throw new InUseException(\sprintf('%s has grade change requests.', $subject->getName()));
        }
        $count = $subject->getComponents()->count();
        $this->activityLogger->log(
            'Deleted subject ' . $subject->getLabel() . ($count > 0 ? \sprintf(' with %d components', $count) : ''),
        );
        $this->subjects->remove($subject);
    }

    /** Adds DepEd's standard subjects for a grade that this year doesn't have yet. */
    public function addDepEdSet(School $school, SchoolYear $schoolYear, GradeLevel $grade): int
    {
        $this->assertWritable($school);
        $taken = new TakenSubjects($this->subjects->findForSchool($school, $schoolYear));
        $position = $this->subjects->nextPosition($school, $schoolYear, $grade);

        $count = 0;
        foreach (DepEdSubjects::forGrade($grade, $schoolYear) as $preset) {
            $subject = $this->add(
                $taken,
                new Subject($school, $schoolYear),
                $grade,
                $preset['code'],
                $preset['name'],
                $position,
            );
            if (null === $subject) {
                continue;
            }
            if ([] === $preset['components']) {
                $subject->setMeetingsPerWeek(self::DEPED_MEETINGS_PER_WEEK);
            }
            ++$position;
            ++$count;
            foreach ($preset['components'] as $i => $component) {
                $part = (new Subject($school, $schoolYear))->setParent($subject);
                if (null !== $this->add($taken, $part, $grade, $component['code'], $component['name'], $i + 1)) {
                    ++$count;
                }
            }
        }

        if ($count > 0) {
            $this->activityLogger->log(\sprintf('Added %d DepEd subjects for %s', $count, $grade->label()));
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
            $grade = $old->getGradeLevel();
            if (null === $grade || !\in_array($grade, $offered, true)) {
                continue;
            }
            $copy = $this->copyShs($old, new Subject($school, $to))->setMeetingsPerWeek($old->getMeetingsPerWeek());
            if (null === $this->add($taken, $copy, $grade, $old->getCode(), $old->getName(), $old->getPosition())) {
                continue;
            }
            ++$count;
            foreach ($old->getComponents() as $component) {
                $part = $this->copyShs($old, (new Subject($school, $to))->setParent($copy))
                    ->setMeetingsPerWeek($component->getMeetingsPerWeek());
                if (
                    null !== $this->add(
                        $taken,
                        $part,
                        $grade,
                        $component->getCode(),
                        $component->getName(),
                        $component->getPosition(),
                    )
                ) {
                    ++$count;
                }
            }
        }

        if ($count > 0) {
            $this->activityLogger->log(\sprintf(
                'Brought back %d subjects to S.Y. %s for %s',
                $count,
                $to->getName(),
                $school->getName(),
            ));
        }

        return $count;
    }

    /** Saves the subject unless its code or name is already taken; null when skipped. */
    private function add(
        TakenSubjects $taken,
        Subject $subject,
        GradeLevel $grade,
        string $code,
        string $name,
        ?int $position,
    ): ?Subject {
        $subject->setGradeLevel($grade)->setCode($code)->setName($name)->setPosition($position);
        if ($taken->has($subject)) {
            $subject->setParent(null);

            return null;
        }
        $taken->add($subject);
        $this->subjects->add($subject);

        return $subject;
    }

    private function copyShs(Subject $from, Subject $to): Subject
    {
        return $to->setSemester($from->getSemester())->setTrack($from->getTrack())->setStrand($from->getStrand());
    }

    private function loadedRefusal(Subject $loadedSubject, int $loaded): InUseException
    {
        return new InUseException(\sprintf(
            '%s is loaded in %d %s. Clear its teachers first.',
            $loadedSubject->getName(),
            $loaded,
            1 === $loaded ? 'section' : 'sections',
        ));
    }

    private function assertWritable(School $school): void
    {
        if (null !== $refusal = $this->refusal($school)) {
            throw new PlanLimitException($refusal);
        }
    }
}
