<?php

namespace App\Service;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\EnrollmentRepository;
use App\Repository\SectionRepository;
use App\Repository\TeachingLoadRepository;
use App\Repository\TransferRequestRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates, changes and removes class sections. Every write is refused while the
 * school's plan is blocked (inactive school or expired plan = view-only).
 */
class SectionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SectionRepository $sections,
        private readonly PlanLimits $planLimits,
        private readonly ActivityLogger $activityLogger,
        private readonly TeachingLoadRepository $loads,
        private readonly EnrollmentRepository $enrollments,
        private readonly TransferRequestRepository $transferRequests,
    ) {
    }

    /** Why sections of this school can't be changed now, or null. */
    public function refusal(School $school): ?string
    {
        return $this->planLimits->blockedReason($school);
    }

    /**
     * Saves a new or edited (already validated) section.
     *
     * @param User|null $previousAdviser the adviser before the edit (null for a new section)
     */
    public function save(Section $section, ?User $previousAdviser = null): void
    {
        $this->assertWritable($section->getSchool());
        $isNew = null === $section->getId();
        if (!$isNew) {
            $this->assertTimetableKept($section);
        }
        if ($isNew) {
            $this->entityManager->persist($section);
        }

        $this->activityLogger->log(($isNew ? 'Created section ' : 'Updated section ').$section->getLabel());
        $adviser = $section->getAdviser();
        if (null !== $adviser && $adviser !== $previousAdviser) {
            $this->activityLogger->log(\sprintf('Assigned adviser %s to %s', $adviser->getUsername(), $section->getLabel()));
        }
        $this->entityManager->flush();
    }

    public function delete(Section $section): void
    {
        $this->assertWritable($section->getSchool());
        if (0 < $learners = $this->enrollments->countForSection($section)) {
            throw new InUseException(\sprintf('%s has %d %s. Move or unenroll them first.', $section->getLabel(), $learners, 1 === $learners ? 'learner' : 'learners'));
        }
        if (0 < $pending = $this->transferRequests->countPendingInto($section)) {
            throw new InUseException(\sprintf('%s has %d pending %s.', $section->getLabel(), $pending, 1 === $pending ? 'transfer' : 'transfers'));
        }
        if ([] !== $this->loads->findForSection($section)) {
            throw $this->hasTimetable($section);
        }
        $this->activityLogger->log('Deleted section '.$section->getLabel());
        $this->entityManager->remove($section);
        $this->entityManager->flush();
    }

    /** Copies last year's sections that this year doesn't have, without advisers. */
    public function bringBack(School $school, SchoolYear $from, SchoolYear $to): int
    {
        $this->assertWritable($school);
        $candidates = $this->sections->findBringBackCandidates($school, $from, $to);
        foreach ($candidates as $old) {
            $this->entityManager->persist((new Section($school, $to))
                ->setGradeLevel($old->getGradeLevel())
                ->setName($old->getName())
                ->setTrack($old->getTrack())
                ->setStrand($old->getStrand()));
        }
        if ([] !== $candidates) {
            $this->activityLogger->log(\sprintf('Brought back %d section(s) to S.Y. %s for %s', \count($candidates), $to->getName(), $school->getName()));
            $this->entityManager->flush();
        }

        return \count($candidates);
    }

    /**
     * A section with loads keeps its grade level, track and strand: its subjects come from them.
     * On refusal the unsaved edit is dropped, so nothing of it is flushed later.
     */
    private function assertTimetableKept(Section $section): void
    {
        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($section);
        $changed = $section->getGradeLevel() !== ($original['gradeLevel'] ?? null)
            || $section->getTrack() !== ($original['track'] ?? null)
            || $section->getStrand() !== ($original['strand'] ?? null);
        if ($changed && [] !== $this->loads->findForSection($section)) {
            $this->entityManager->refresh($section);

            throw $this->hasTimetable($section);
        }
    }

    private function hasTimetable(Section $section): InUseException
    {
        return new InUseException(\sprintf('%s has a timetable. Remove its classes and teachers first.', $section->getLabel()));
    }

    private function assertWritable(School $school): void
    {
        if (null !== $refusal = $this->refusal($school)) {
            throw new PlanLimitException($refusal);
        }
    }
}
