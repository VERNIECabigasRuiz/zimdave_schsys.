<?php

declare(strict_types=1);

namespace App\Tests\Classes\Integration\Application;

use App\Classes\Application\Service\Subject\SubjectScopeKey;
use App\Classes\Application\Service\SubjectManager;
use App\Classes\Application\Transformer\SubjectTransformer;
use App\Classes\Application\UseCase\Command\UpdateSubjectCommand;
use App\Classes\Domain\Model\Subject;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;

/** Calls the subject manager as a use case would, saving after each call. */
final class SavingSubjectManager
{
    public function __construct(
        private readonly SubjectManager $manager,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Creates a new subject, or saves the values an existing one holds now as an edit. */
    public function save(Subject $subject): void
    {
        if (null === $subject->getId()) {
            $this->manager->create($subject);
        } else {
            $details = UpdateSubjectCommand::fromSubject(
                SubjectScopeKey::school((int) $subject->getSchool()->getId()),
                SubjectTransformer::toArray($subject),
            );
            $this->manager->update($subject, $details, $subject->getParent());
        }
        $this->em->flush();
    }

    public function delete(Subject $subject): void
    {
        $this->manager->delete($subject);
        $this->em->flush();
    }

    public function addDepEdSet(School $school, SchoolYear $schoolYear, GradeLevel $grade): int
    {
        $count = $this->manager->addDepEdSet($school, $schoolYear, $grade);
        $this->em->flush();

        return $count;
    }

    /** @param list<GradeLevel>|null $grades */
    public function bringBack(School $school, SchoolYear $from, SchoolYear $to, ?array $grades = null): int
    {
        $count = $this->manager->bringBack($school, $from, $to, $grades);
        $this->em->flush();

        return $count;
    }

    public function refusal(School $school): ?string
    {
        return $this->manager->refusal($school);
    }
}
