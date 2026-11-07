<?php

declare(strict_types=1);

namespace App\Schools\Infrastructure\Repository;

use App\Schools\Domain\Model\Division;
use App\Schools\Domain\Model\Repository\DivisionRepositoryInterface;
use App\Schools\Domain\Model\School;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Division>
 */
class DoctrineDivisionRepository extends ServiceEntityRepository implements DivisionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Division::class);
    }

    public function findById(int $id): ?Division
    {
        return $this->find($id);
    }

    public function findAllOrdered(): array
    {
        return $this->findBy([], ['region' => 'ASC', 'name' => 'ASC']);
    }

    public function findForSchool(School $school): ?Division
    {
        return $this->findByRegionAndName($school->getRegion(), $school->getDivision());
    }

    public function findByRegionAndName(string $region, string $name): ?Division
    {
        return $this->findOneBy(['region' => $region, 'name' => $name]);
    }

    public function add(Division $division): void
    {
        $this->getEntityManager()->persist($division);
    }
}
