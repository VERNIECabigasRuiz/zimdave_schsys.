<?php

namespace App\Repository;

use App\Entity\School;
use App\Entity\SchoolDayOff;
use App\Entity\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolDayOff>
 */
class SchoolDayOffRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolDayOff::class);
    }

    /** @return array<string, SchoolDayOff> keyed by Y-m-d */
    public function findBetween(School $school, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $daysOff = $this->createQueryBuilder('d')
            ->andWhere('d.school = :school')->setParameter('school', $school)
            ->andWhere('d.date BETWEEN :from AND :to')
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->getQuery()->getResult();

        $byDate = [];
        foreach ($daysOff as $dayOff) {
            $byDate[$dayOff->getDate()->format('Y-m-d')] = $dayOff;
        }

        return $byDate;
    }

    /** @return list<SchoolDayOff> by date */
    public function findForYear(School $school, SchoolYear $schoolYear): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.school = :school')->setParameter('school', $school)
            ->andWhere('d.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->orderBy('d.date', 'ASC')
            ->getQuery()->getResult();
    }
}
