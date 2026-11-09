<?php

declare(strict_types=1);

namespace App\Grading\Infrastructure\Repository;

use App\Grading\Domain\Model\GradeLock;
use App\Grading\Domain\Model\Repository\GradeLockRepositoryInterface;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GradeLock>
 */
class DoctrineGradeLockRepository extends ServiceEntityRepository implements GradeLockRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GradeLock::class);
    }

    public function findFor(School $school, GradingPeriod $period): ?GradeLock
    {
        return $this->findOneBy(['school' => $school, 'gradingPeriod' => $period]);
    }

    public function countForPeriod(GradingPeriod $period): int
    {
        return $this->count(['gradingPeriod' => $period]);
    }

    public function add(GradeLock $lock): void
    {
        $this->getEntityManager()->persist($lock);
    }

    public function remove(GradeLock $lock): void
    {
        $this->getEntityManager()->remove($lock);
    }
}
