<?php

declare(strict_types=1);

namespace App\Schools\Infrastructure\Repository;

use App\Schools\Domain\Model\Repository\SchoolDayOffRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolDayOff>
 */
class DoctrineSchoolDayOffRepository extends ServiceEntityRepository implements SchoolDayOffRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolDayOff::class);
    }

    public function findById(int $id): ?SchoolDayOff
    {
        return $this->find($id);
    }

    public function findBetween(School $school, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<SchoolDayOff> $daysOff */
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

    public function findForYear(School $school, SchoolYear $schoolYear): array
    {
        /** @var list<SchoolDayOff> */
        return $this->createQueryBuilder('d')
            ->andWhere('d.school = :school')->setParameter('school', $school)
            ->andWhere('d.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->orderBy('d.date', 'ASC')
            ->getQuery()->getResult();
    }

    public function add(SchoolDayOff $dayOff): void
    {
        $this->getEntityManager()->persist($dayOff);
    }

    public function remove(SchoolDayOff $dayOff): void
    {
        $this->getEntityManager()->remove($dayOff);
    }
}
