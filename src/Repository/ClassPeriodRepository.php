<?php

namespace App\Repository;

use App\Entity\ClassPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClassPeriod>
 */
class ClassPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassPeriod::class);
    }

    /** @return list<ClassPeriod> in time order, breaks included */
    public function findForSchool(School $school, SchoolYear $schoolYear): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.school = :school')->setParameter('school', $school)
            ->andWhere('p.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->addOrderBy('p.startsAt', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @param list<ClassPeriod> $periods in time order
     *
     * @return array<int, int> period id => its number among the class periods (breaks have none)
     */
    public function numbering(array $periods): array
    {
        $numbers = [];
        $n = 0;
        foreach ($periods as $period) {
            if (!$period->isBreak()) {
                $numbers[$period->getId()] = ++$n;
            }
        }

        return $numbers;
    }

    /** "Period 3", or the break label. */
    public function label(ClassPeriod $period): string
    {
        if ($period->isBreak()) {
            return (string) $period->getBreakLabel();
        }
        $numbers = $this->numbering($this->findForSchool($period->getSchool(), $period->getSchoolYear()));

        return 'Period '.($numbers[$period->getId()] ?? '?');
    }
}
