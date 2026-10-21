<?php

namespace App\Service;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\SectionRepository;
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

    private function assertWritable(School $school): void
    {
        if (null !== $refusal = $this->refusal($school)) {
            throw new PlanLimitException($refusal);
        }
    }
}
