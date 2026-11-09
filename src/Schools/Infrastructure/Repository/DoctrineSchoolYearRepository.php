<?php

declare(strict_types=1);

namespace App\Schools\Infrastructure\Repository;

use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolYear>
 */
class DoctrineSchoolYearRepository extends ServiceEntityRepository implements SchoolYearRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolYear::class);
    }

    public function findById(int $id): ?SchoolYear
    {
        return $this->find($id);
    }

    public function findByName(string $name): ?SchoolYear
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findCurrent(): ?SchoolYear
    {
        return $this->findOneBy(['current' => true]);
    }

    public function findAllNewestFirst(): array
    {
        return $this->findBy([], ['startDate' => 'DESC']);
    }

    public function findPrevious(SchoolYear $schoolYear): ?SchoolYear
    {
        /** @var SchoolYear|null */
        return $this->createQueryBuilder('sy')
            ->where('sy.startDate < :start')
            ->setParameter('start', $schoolYear->getStartDate())
            ->orderBy('sy.startDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function makeCurrent(SchoolYear $schoolYear): void
    {
        foreach ($this->findBy(['current' => true]) as $other) {
            $other->setCurrent(false);
        }
        $schoolYear->setCurrent(true);
    }

    public function add(SchoolYear $schoolYear): void
    {
        $this->getEntityManager()->persist($schoolYear);
    }
}
