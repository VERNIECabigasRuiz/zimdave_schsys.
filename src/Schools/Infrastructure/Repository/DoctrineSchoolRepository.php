<?php

declare(strict_types=1);

namespace App\Schools\Infrastructure\Repository;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Schools\Domain\Model\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<School>
 */
class DoctrineSchoolRepository extends ServiceEntityRepository implements SchoolRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, School::class);
    }

    public function findById(int $id): ?School
    {
        return $this->find($id);
    }

    public function findBySchoolId(string $schoolId): ?School
    {
        return $this->findOneBy(['schoolId' => $schoolId]);
    }

    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    public function countActive(): int
    {
        return $this->count(['active' => true]);
    }

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

    public function countByPlan(): array
    {
        /** @var list<array{planId: int|string, schools: int|string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.plan) AS planId', 'COUNT(s.id) AS schools')
            ->groupBy('s.plan')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['planId']] = (int) $row['schools'];
        }

        return $counts;
    }

    public function add(School $school): void
    {
        $this->getEntityManager()->persist($school);
    }
}
