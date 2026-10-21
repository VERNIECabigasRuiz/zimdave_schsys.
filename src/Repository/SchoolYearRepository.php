<?php

namespace App\Repository;

use App\Entity\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolYear>
 */
class SchoolYearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolYear::class);
    }

    public function findCurrent(): ?SchoolYear
    {
        return $this->findOneBy(['current' => true]);
    }

    /**
     * @return list<SchoolYear>
     */
    public function findAllNewestFirst(): array
    {
        return $this->findBy([], ['startDate' => 'DESC']);
    }

    /**
     * The school year that started right before $schoolYear.
     */
    public function findPrevious(SchoolYear $schoolYear): ?SchoolYear
    {
        return $this->createQueryBuilder('sy')
            ->where('sy.startDate < :start')
            ->setParameter('start', $schoolYear->getStartDate())
            ->orderBy('sy.startDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Makes $schoolYear the only current school year.
     */
    public function makeCurrent(SchoolYear $schoolYear): void
    {
        foreach ($this->findBy(['current' => true]) as $other) {
            $other->setCurrent(false);
        }
        $schoolYear->setCurrent(true);
    }
}
