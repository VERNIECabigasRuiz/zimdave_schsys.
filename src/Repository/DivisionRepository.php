<?php

namespace App\Repository;

use App\Entity\Division;
use App\Entity\School;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Division>
 */
class DivisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Division::class);
    }

    /** @return list<Division> by region, then name */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['region' => 'ASC', 'name' => 'ASC']);
    }

    /** The entry with the school's region and division, if the admin has added it. */
    public function findForSchool(School $school): ?Division
    {
        return $this->findOneBy(['region' => $school->getRegion(), 'name' => $school->getDivision()]);
    }
}
