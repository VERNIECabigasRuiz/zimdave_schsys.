<?php

namespace App\Repository;

use App\Entity\School;
use App\Entity\SchoolAssignment;
use App\Entity\SchoolYear;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<School>
 */
class SchoolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, School::class);
    }

    /**
     * @return list<School>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * Active schools whose plan ends on or before $limitDate (already expired ones included).
     */
    public function countExpiringBy(\DateTimeImmutable $limitDate): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.active = true')
            ->andWhere('s.planValidUntil IS NOT NULL')
            ->andWhere('s.planValidUntil <= :limit')
            ->setParameter('limit', $limitDate, 'date_immutable')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActiveWithoutPrincipal(SchoolYear $schoolYear): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->leftJoin(SchoolAssignment::class, 'a', 'WITH', 'a.school = s AND a.schoolYear = :sy AND a.role = :role')
            ->where('s.active = true')
            ->andWhere('a.id IS NULL')
            ->setParameter('sy', $schoolYear)
            ->setParameter('role', User::ROLE_PRINCIPAL)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
