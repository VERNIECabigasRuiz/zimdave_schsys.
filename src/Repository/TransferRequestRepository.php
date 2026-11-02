<?php

namespace App\Repository;

use App\Entity\Enrollment;
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

    /** The learner's waiting request out of a section of the school year; older years' requests are no longer actionable. */
    public function findPending(Learner $learner, SchoolYear $schoolYear): ?TransferRequest
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.fromSection', 'fs')
            ->andWhere('t.learner = :learner')->setParameter('learner', $learner)
            ->andWhere('t.status = :pending')->setParameter('pending', TransferStatus::Pending)
            ->andWhere('fs.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** The latest approved transfer that brought this enrollment to its present school. */
    public function findApprovedInto(Enrollment $enrollment): ?TransferRequest
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.toSection', 'ts')
            ->andWhere('t.enrollment = :enrollment')->setParameter('enrollment', $enrollment)
            ->andWhere('t.status = :approved')->setParameter('approved', TransferStatus::Approved)
            ->andWhere('ts.school = :school')->setParameter('school', $enrollment->getSchool())
            ->orderBy('t.decidedAt', 'DESC')->addOrderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * The latest approved transfer that brought each of the section's enrollments to its school, in one query.
     *
     * @return array<int, TransferRequest> keyed by enrollment id
     */
    public function findApprovedIntoSection(Section $section): array
    {
        $transfers = $this->createQueryBuilder('t')
            ->innerJoin('t.enrollment', 'e')->addSelect('e')
            ->innerJoin('t.toSection', 'ts')
            ->innerJoin('t.fromSchool', 'fs')->addSelect('fs')
            ->andWhere('e.section = :section')->setParameter('section', $section)
            ->andWhere('t.status = :approved')->setParameter('approved', TransferStatus::Approved)
            ->andWhere('ts.school = e.school')
            ->orderBy('t.decidedAt', 'ASC')->addOrderBy('t.id', 'ASC')
            ->getQuery()->getResult();

        $byEnrollment = [];
        foreach ($transfers as $transfer) {
            // the latest wins, as in findApprovedInto()
            $byEnrollment[$transfer->getEnrollment()->getId()] = $transfer;
        }

        return $byEnrollment;
    }

    /**
     * Approved transfers out of the section decided on or after $since.
     *
     * @return list<TransferRequest>
     */
    public function findApprovedOutOf(Section $section, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.enrollment', 'e')->addSelect('e')
            ->andWhere('t.fromSection = :section')->setParameter('section', $section)
            ->andWhere('t.status = :approved')->setParameter('approved', TransferStatus::Approved)
            ->andWhere('t.decidedAt >= :since')->setParameter('since', $since->setTime(0, 0))
            ->getQuery()->getResult();
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
     * Requests out of the school (or only out of $fromSection) for the school year, pending first, then newest.
     *
     * @return list<TransferRequest>
     */
    public function findOutgoing(School $school, SchoolYear $schoolYear, ?Section $fromSection = null): array
    {
        $qb = $this->listing()
            ->andWhere('t.fromSchool = :school')->setParameter('school', $school)
            ->andWhere('fs.schoolYear = :sy')->setParameter('sy', $schoolYear);
        if (null !== $fromSection) {
            $qb->andWhere('t.fromSection = :section')->setParameter('section', $fromSection);
        }

        return $qb->getQuery()->getResult();
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
            ->addSelect('l', 'u')->innerJoin('t.learner', 'l')->innerJoin('l.user', 'u')
            ->innerJoin('t.fromSection', 'fs')
            ->innerJoin('t.toSection', 'ts')
            ->addSelect('CASE WHEN t.status = :pending THEN 0 ELSE 1 END AS HIDDEN pendingRank')
            ->setParameter('pending', TransferStatus::Pending)
            ->addOrderBy('pendingRank', 'ASC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC');
    }
}
