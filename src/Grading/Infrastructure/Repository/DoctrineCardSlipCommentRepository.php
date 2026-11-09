<?php

declare(strict_types=1);

namespace App\Grading\Infrastructure\Repository;

use App\Grading\Domain\Model\CardSlipComment;
use App\Grading\Domain\Model\Repository\CardSlipCommentRepositoryInterface;
use App\Schools\Domain\Model\GradingPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CardSlipComment>
 */
class DoctrineCardSlipCommentRepository extends ServiceEntityRepository implements CardSlipCommentRepositoryInterface
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

        /** @var list<CardSlipComment> $comments */
        $comments = $this->createQueryBuilder('c')
            ->andWhere('c.enrollment IN (:ids)')
            ->andWhere('c.gradingPeriod = :period')
            ->setParameter('ids', $enrollmentIds)
            ->setParameter('period', $period)
            ->getQuery()
            ->getResult();
        $found = [];
        foreach ($comments as $comment) {
            $found[(int) $comment->getEnrollment()->getId()] = $comment;
        }

        return $found;
    }

    public function add(CardSlipComment $comment): void
    {
        $this->getEntityManager()->persist($comment);
    }

    public function remove(CardSlipComment $comment): void
    {
        $this->getEntityManager()->remove($comment);
    }
}
