<?php

namespace App\Repository;

use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransferRequest>
 */
class TransferRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransferRequest::class);
    }

    public function findPending(Learner $learner): ?TransferRequest
    {
        return $this->findOneBy(['learner' => $learner, 'status' => TransferStatus::Pending]);
    }

    /**
     * Requests into the school for the school year, pending first, then newest.
     *
     * @return list<TransferRequest>
     */
    public function findIncoming(School $school, SchoolYear $schoolYear): array
    {
        return $this->listing()
            ->andWhere('ts.school = :school')->setParameter('school', $school)
            ->andWhere('ts.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->getQuery()->getResult();
    }

    /**
     * Requests out of the school for the school year, pending first, then newest.
     *
     * @return list<TransferRequest>
     */
    public function findOutgoing(School $school, SchoolYear $schoolYear): array
    {
        return $this->listing()
            ->andWhere('t.fromSchool = :school')->setParameter('school', $school)
            ->andWhere('fs.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->getQuery()->getResult();
    }

    public function countPendingOutgoing(School $school, SchoolYear $schoolYear, ?Section $fromSection = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->innerJoin('t.fromSection', 'fs')
            ->andWhere('t.status = :pending')->setParameter('pending', TransferStatus::Pending)
            ->andWhere('t.fromSchool = :school')->setParameter('school', $school)
            ->andWhere('fs.schoolYear = :sy')->setParameter('sy', $schoolYear);
        if (null !== $fromSection) {
            $qb->andWhere('t.fromSection = :section')->setParameter('section', $fromSection);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countPendingInto(Section $section): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.status = :pending')->setParameter('pending', TransferStatus::Pending)
            ->andWhere('t.toSection = :section')->setParameter('section', $section)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<TransferRequest> newest first */
    public function findForLearner(Learner $learner): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.learner = :learner')->setParameter('learner', $learner)
            ->orderBy('t.createdAt', 'DESC')->addOrderBy('t.id', 'DESC')
            ->getQuery()->getResult();
    }

    private function listing(): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->addSelect('l')->innerJoin('t.learner', 'l')
            ->innerJoin('t.fromSection', 'fs')
            ->innerJoin('t.toSection', 'ts')
            ->addSelect('CASE WHEN t.status = :pending THEN 0 ELSE 1 END AS HIDDEN pendingRank')
            ->setParameter('pending', TransferStatus::Pending)
            ->addOrderBy('pendingRank', 'ASC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC');
    }
}
