<?php

namespace App\Repository;

use App\Entity\RollCall;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RollCall>
 */
class RollCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RollCall::class);
    }

    public function findOne(Section $section, \DateTimeImmutable $date): ?RollCall
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.marks', 'm')->addSelect('m')
            ->andWhere('r.section = :section')->setParameter('section', $section)
            ->andWhere('r.date = :date')->setParameter('date', $date->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return array<string, RollCall> keyed by Y-m-d, with their marks */
    public function findBetween(Section $section, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rollCalls = $this->createQueryBuilder('r')
            ->leftJoin('r.marks', 'm')->addSelect('m')
            ->andWhere('r.section = :section')->setParameter('section', $section)
            ->andWhere('r.date BETWEEN :from AND :to')
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->setParameter('to', $to->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('r.date', 'ASC')
            ->getQuery()->getResult();

        $byDate = [];
        foreach ($rollCalls as $rollCall) {
            $byDate[$rollCall->getDate()->format('Y-m-d')] = $rollCall;
        }

        return $byDate;
    }

    /** @return array<int, RollCall> the school's roll calls of the day, keyed by section id */
    public function findForSchoolOn(School $school, SchoolYear $schoolYear, \DateTimeImmutable $date): array
    {
        $rollCalls = $this->createQueryBuilder('r')
            ->innerJoin('r.section', 's')
            ->leftJoin('r.marks', 'm')->addSelect('m')
            ->andWhere('s.school = :school')->setParameter('school', $school)
            ->andWhere('s.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->andWhere('r.date = :date')->setParameter('date', $date->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->getQuery()->getResult();

        $bySection = [];
        foreach ($rollCalls as $rollCall) {
            $bySection[$rollCall->getSection()->getId()] = $rollCall;
        }

        return $bySection;
    }

    /** How many of the school's sections took attendance on any of $dates. */
    public function countSectionsOn(School $school, array $dates): int
    {
        if ([] === $dates) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT s.id)')
            ->innerJoin('r.section', 's')
            ->andWhere('s.school = :school')->setParameter('school', $school)
            ->andWhere('r.date IN (:dates)')
            ->setParameter('dates', array_map(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates), ArrayParameterType::STRING)
            ->getQuery()->getSingleScalarResult();
    }
}
