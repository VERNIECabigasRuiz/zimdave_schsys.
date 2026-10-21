<?php

namespace App\Repository;

use App\Entity\PersonalDataSheet;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonalDataSheet>
 */
class PersonalDataSheetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonalDataSheet::class);
    }

    /** The user's PDS; a new one is persisted (not flushed) on first use. */
    public function findOrCreateFor(User $user): PersonalDataSheet
    {
        $sheet = $this->findOneBy(['user' => $user]);
        if (null === $sheet) {
            $sheet = new PersonalDataSheet($user);
            $this->getEntityManager()->persist($sheet);
        }

        return $sheet;
    }
}
