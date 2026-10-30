<?php

namespace App\Repository;

use App\Entity\Grade;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\Section;
use App\Entity\Subject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Grade>
 */
class GradeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Grade::class);
    }

    /** @return array<int, Grade> keyed by enrollment id */
    public function findForSheet(Section $section, Subject $subject, GradingPeriod $period): array
    {
        $grades = $this->createQueryBuilder('g')
            ->join('g.enrollment', 'e')
            ->andWhere('e.section = :section')->setParameter('section', $section)
            ->andWhere('g.subject = :subject')->setParameter('subject', $subject)
            ->andWhere('g.gradingPeriod = :period')->setParameter('period', $period)
            ->getQuery()->getResult();

        $byEnrollment = [];
        foreach ($grades as $grade) {
            $byEnrollment[$grade->getEnrollment()->getId()] = $grade;
        }

        return $byEnrollment;
    }

    /** @return array<int, array<int, Grade>> keyed by enrollment id, then subject id */
    public function findForSection(Section $section, GradingPeriod $period): array
    {
        $grades = $this->createQueryBuilder('g')
            ->join('g.enrollment', 'e')
            ->andWhere('e.section = :section')->setParameter('section', $section)
            ->andWhere('g.gradingPeriod = :period')->setParameter('period', $period)
            ->getQuery()->getResult();

        $byEnrollment = [];
        foreach ($grades as $grade) {
            $byEnrollment[$grade->getEnrollment()->getId()][$grade->getSubject()->getId()] = $grade;
        }

        return $byEnrollment;
    }

    /** Grades entered in the school's sections for the period. */
    public function countForSchool(School $school, GradingPeriod $period): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->join('g.enrollment', 'e')
            ->join('e.section', 's')
            ->andWhere('s.school = :school')->setParameter('school', $school)
            ->andWhere('g.gradingPeriod = :period')->setParameter('period', $period)
            ->getQuery()->getSingleScalarResult();
    }

    /** Learners with a grade in the subject or in one of its components. */
    public function countForSubject(Subject $subject): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(DISTINCT g.enrollment)')
            ->join('g.subject', 's')
            ->andWhere('s = :subject OR s.parent = :subject')->setParameter('subject', $subject)
            ->getQuery()->getSingleScalarResult();
    }
}
