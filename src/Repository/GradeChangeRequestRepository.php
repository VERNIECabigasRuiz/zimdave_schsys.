<?php

namespace App\Repository;

use App\Entity\GradeChangeRequest;
use App\Entity\GradeChangeStatus;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GradeChangeRequest>
 */
class GradeChangeRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GradeChangeRequest::class);
    }

    public function findPendingFor(Section $section, Subject $subject, GradingPeriod $period): ?GradeChangeRequest
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.section = :section')->setParameter('section', $section)
            ->andWhere('r.subject = :subject')->setParameter('subject', $subject)
            ->andWhere('r.gradingPeriod = :period')->setParameter('period', $period)
            ->andWhere('r.status = :pending')->setParameter('pending', GradeChangeStatus::Pending)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<GradeChangeRequest> newest first */
    public function findForClass(Section $section, Subject $subject, GradingPeriod $period): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.section = :section')->setParameter('section', $section)
            ->andWhere('r.subject = :subject')->setParameter('subject', $subject)
            ->andWhere('r.gradingPeriod = :period')->setParameter('period', $period)
            ->orderBy('r.createdAt', 'DESC')->addOrderBy('r.id', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return list<GradeChangeRequest> oldest first */
    public function findPendingForSchool(School $school, SchoolYear $schoolYear): array
    {
        return $this->forSchool($school, $schoolYear)
            ->andWhere('r.status = :pending')->setParameter('pending', GradeChangeStatus::Pending)
            ->orderBy('r.createdAt', 'ASC')->addOrderBy('r.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<GradeChangeRequest> newest first */
    public function findDecidedForSchool(School $school, SchoolYear $schoolYear): array
    {
        return $this->forSchool($school, $schoolYear)
            ->andWhere('r.status != :pending')->setParameter('pending', GradeChangeStatus::Pending)
            ->orderBy('r.decidedAt', 'DESC')->addOrderBy('r.id', 'DESC')
            ->getQuery()->getResult();
    }

    public function countPendingForSchool(School $school, SchoolYear $schoolYear): int
    {
        return (int) $this->forSchool($school, $schoolYear)
            ->select('COUNT(r.id)')
            ->andWhere('r.status = :pending')->setParameter('pending', GradeChangeStatus::Pending)
            ->getQuery()->getSingleScalarResult();
    }

    private function forSchool(School $school, SchoolYear $schoolYear): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.section', 's')
            ->andWhere('s.school = :school')->setParameter('school', $school)
            ->andWhere('s.schoolYear = :sy')->setParameter('sy', $schoolYear);
    }
}
