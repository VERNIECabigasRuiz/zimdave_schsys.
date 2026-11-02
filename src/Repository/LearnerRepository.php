<?php

namespace App\Repository;

use App\Entity\Learner;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Learner>
 */
class LearnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Learner::class);
    }

    public function findOneByLrn(string $lrn): ?Learner
    {
        return $this->findOneBy(['lrn' => $lrn]);
    }

    /**
     * A 12-digit query is an exact LRN; anything else is a fragment of the name.
     *
     * @return list<Learner>
     */
    public function searchByLrnOrName(string $query, int $limit): array
    {
        $qb = $this->createQueryBuilder('l')
            ->orderBy('l.lastName', 'ASC')->addOrderBy('l.firstName', 'ASC')
            ->setMaxResults($limit);
        if (1 === preg_match('/^\d{12}$/', $query)) {
            $qb->andWhere('l.lrn = :lrn')->setParameter('lrn', $query);
        } else {
            $qb->andWhere('l.lastName LIKE :q OR l.firstName LIKE :q OR l.middleName LIKE :q')
                ->setParameter('q', '%'.addcslashes($query, '%_\\').'%');
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByUser(User $user): ?Learner
    {
        return $this->findOneBy(['user' => $user]);
    }
}
