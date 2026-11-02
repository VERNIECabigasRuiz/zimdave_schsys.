<?php

namespace App\Repository;

use App\Entity\GradeUnlock;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\Section;
use App\Entity\Subject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GradeUnlock>
 */
class GradeUnlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GradeUnlock::class);
    }

    public function findFor(Section $section, Subject $subject, GradingPeriod $period): ?GradeUnlock
    {
        return $this->findOneBy(['section' => $section, 'subject' => $subject, 'gradingPeriod' => $period]);
    }

    /** @return list<GradeUnlock> */
    public function findForSchool(School $school, GradingPeriod $period): array
    {
        return $this->createQueryBuilder('u')
            ->join('u.section', 's')
            ->andWhere('s.school = :school')->setParameter('school', $school)
            ->andWhere('u.gradingPeriod = :period')->setParameter('period', $period)
            ->getQuery()->getResult();
    }

    /** Removes the school's class unlocks for the period; returns how many. */
    public function deleteForSchool(School $school, GradingPeriod $period): int
    {
        $ids = array_map(static fn (GradeUnlock $u) => $u->getId(), $this->findForSchool($school, $period));
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->createQueryBuilder('u')
            ->delete()
            ->andWhere('u.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->execute();
    }
}
