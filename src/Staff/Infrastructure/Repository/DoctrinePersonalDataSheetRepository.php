<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure\Repository;

use App\Access\Domain\Model\User;
use App\Staff\Domain\Model\PersonalDataSheet;
use App\Staff\Domain\Model\Repository\PersonalDataSheetRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonalDataSheet>
 */
class DoctrinePersonalDataSheetRepository extends ServiceEntityRepository implements
    PersonalDataSheetRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonalDataSheet::class);
    }

    public function findFor(User $user): ?PersonalDataSheet
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function add(PersonalDataSheet $sheet): void
    {
        $this->getEntityManager()->persist($sheet);
    }
}
