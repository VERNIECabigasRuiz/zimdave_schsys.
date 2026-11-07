<?php

declare(strict_types=1);

namespace App\Classes\Application\Service;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Exception\InUseException;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Classes\Domain\Model\Repository\TeachingLoadRepositoryInterface;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\ShsTrack;
use App\Classes\Domain\Service\Usage\SectionLearnersInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\PlanLimitException;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/**
 * Creates, changes and removes class sections. Every write is refused while the
 * school's plan is blocked (inactive school or expired plan = view-only).
 */
class SectionManager
{
    public function __construct(
        private readonly SectionRepositoryInterface $sections,
        private readonly SchoolDirectoryInterface $schools,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly TeachingLoadRepositoryInterface $loads,
        private readonly SectionLearnersInterface $learners,
    ) {
    }

    /** Why sections of this school can't be changed now, or null. */
    public function refusal(School $school): ?string
    {
        return $this->schools->blockedReason($school);
    }

    /** Saves a new (already validated) section. */
    public function create(Section $section): void
    {
        $this->assertWritable($section->getSchool());
        $this->sections->add($section);

        $this->activityLogger->log('Created section ' . $section->getLabel());
        $this->logAdviser($section, null);
    }

    /**
     * Applies an (already validated) edit. A section with loads keeps its grade level, track and
     * strand, since its subjects come from them; such an edit is refused before anything changes.
     */
    public function update(
        Section $section,
        GradeLevel $gradeLevel,
        string $name,
        ?ShsTrack $track,
        ?string $strand,
        ?User $adviser,
    ): void {
        $this->assertWritable($section->getSchool());
        $changed = $gradeLevel !== $section->getGradeLevel()
            || $track !== $section->getTrack()
            || Section::normalizeStrand($strand) !== $section->getStrand();
        if ($changed && [] !== $this->loads->findForSection($section)) {
            throw $this->hasTimetable($section);
        }

        $previousAdviser = $section->getAdviser();
        $section->setGradeLevel($gradeLevel)
            ->setName($name)
            ->setTrack($track)
            ->setStrand($strand)
            ->setAdviser($adviser);
        $this->activityLogger->log('Updated section ' . $section->getLabel());
        $this->logAdviser($section, $previousAdviser);
    }

    public function delete(Section $section): void
    {
        $this->assertWritable($section->getSchool());
        if (0 < $learners = $this->learners->countEnrolled($section)) {
            throw new InUseException(\sprintf(
                '%s has %d %s. Move or unenroll them first.',
                $section->getLabel(),
                $learners,
                1 === $learners ? 'learner' : 'learners',
            ));
        }
        if (0 < $pending = $this->learners->countPendingTransfersInto($section)) {
            throw new InUseException(\sprintf(
                '%s has %d pending %s.',
                $section->getLabel(),
                $pending,
                1 === $pending ? 'transfer' : 'transfers',
            ));
        }
        if ([] !== $this->loads->findForSection($section)) {
            throw $this->hasTimetable($section);
        }
        $this->activityLogger->log('Deleted section ' . $section->getLabel());
        $this->sections->remove($section);
    }

    /** Copies last year's sections that this year doesn't have, without advisers. */
    public function bringBack(School $school, SchoolYear $from, SchoolYear $to): int
    {
        $this->assertWritable($school);
        $candidates = $this->sections->findBringBackCandidates($school, $from, $to);
        foreach ($candidates as $old) {
            $this->sections->add((new Section($school, $to))
                ->setGradeLevel($old->getGradeLevel())
                ->setName($old->getName())
                ->setTrack($old->getTrack())
                ->setStrand($old->getStrand()));
        }
        if ([] !== $candidates) {
            $this->activityLogger->log(\sprintf(
                'Brought back %d section(s) to S.Y. %s for %s',
                \count($candidates),
                $to->getName(),
                $school->getName(),
            ));
        }

        return \count($candidates);
    }

    private function logAdviser(Section $section, ?User $previousAdviser): void
    {
        $adviser = $section->getAdviser();
        if (null !== $adviser && $adviser !== $previousAdviser) {
            $this->activityLogger->log(\sprintf(
                'Assigned adviser %s to %s',
                $adviser->getUsername(),
                $section->getLabel(),
            ));
        }
    }

    private function hasTimetable(Section $section): InUseException
    {
        return new InUseException(\sprintf(
            '%s has a timetable. Remove its classes and teachers first.',
            $section->getLabel(),
        ));
    }

    private function assertWritable(School $school): void
    {
        if (null !== $refusal = $this->refusal($school)) {
            throw new PlanLimitException($refusal);
        }
    }
}
