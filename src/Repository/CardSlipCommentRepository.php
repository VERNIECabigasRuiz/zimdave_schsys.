<?php

namespace App\Repository;

use App\Entity\CardSlipComment;
use App\Entity\GradingPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CardSlipComment>
 */
class CardSlipCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardSlipComment::class);
    }

    /**
     * @param list<int> $enrollmentIds
     *
     * @return array<int, CardSlipComment> keyed by enrollment id
     */
    public function findForEnrollments(array $enrollmentIds, GradingPeriod $period): array
    {
        if ([] === $enrollmentIds) {
            return [];
        }

        $found = [];
        foreach ($this->createQueryBuilder('c')
            ->andWhere('c.enrollment IN (:ids)')
            ->andWhere('c.gradingPeriod = :period')
            ->setParameter('ids', $enrollmentIds)
            ->setParameter('period', $period)
            ->getQuery()
            ->getResult() as $comment) {
            $found[$comment->getEnrollment()->getId()] = $comment;
        }

        return $found;
    }
}
