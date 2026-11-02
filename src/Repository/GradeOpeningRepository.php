<?php

namespace App\Repository;

use App\Entity\GradeOpening;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GradeOpening>
 */
class GradeOpeningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GradeOpening::class);
    }

    public function findFor(School $school, GradingPeriod $period): ?GradeOpening
    {
        return $this->findOneBy(['school' => $school, 'gradingPeriod' => $period]);
    }

    /** @return list<int> ids of the school's open grading periods in the school year */
    public function findOpenPeriodIds(School $school, SchoolYear $schoolYear): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('IDENTITY(o.gradingPeriod) AS id')
            ->join('o.gradingPeriod', 'p')
            ->andWhere('o.school = :school')
            ->andWhere('p.schoolYear = :sy')
            ->setParameter('school', $school)
            ->setParameter('sy', $schoolYear)
            ->orderBy('p.number')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /** Whether any school has opened any period of the school year. */
    public function existsForSchoolYear(SchoolYear $schoolYear): bool
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->join('o.gradingPeriod', 'p')
            ->andWhere('p.schoolYear = :sy')
            ->setParameter('sy', $schoolYear)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function countSchoolsOpen(GradingPeriod $period): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.gradingPeriod = :period')
            ->setParameter('period', $period)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
