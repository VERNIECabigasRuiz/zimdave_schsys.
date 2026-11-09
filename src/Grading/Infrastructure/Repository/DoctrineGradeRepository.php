<?php

declare(strict_types=1);

namespace App\Grading\Infrastructure\Repository;

use App\Learners\Domain\Model\Enrollment;
use App\Grading\Domain\Model\Grade;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Classes\Domain\Model\Subject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Grade>
 */
class DoctrineGradeRepository extends ServiceEntityRepository implements GradeRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Grade::class);
    }

    /**
     * The grades of these enrollments in the subject for the period. Looked up by enrollment, not by
     * section: an approved transfer moves the enrollment to the receiving section, and the grades go with it.
     *
     * @param list<int> $enrollmentIds
     *
     * @return array<int, Grade> keyed by enrollment id
     */
    public function findForEnrollments(array $enrollmentIds, Subject $subject, GradingPeriod $period): array
    {
        return $this->forEnrollments($enrollmentIds, $subject, $period, fresh: false);
    }

    public function findFreshForEnrollments(array $enrollmentIds, Subject $subject, GradingPeriod $period): array
    {
        return $this->forEnrollments($enrollmentIds, $subject, $period, fresh: true);
    }

    /**
     * @param list<int> $enrollmentIds
     *
     * @return array<int, Grade> keyed by enrollment id
     */
    private function forEnrollments(array $enrollmentIds, Subject $subject, GradingPeriod $period, bool $fresh): array
    {
        if ([] === $enrollmentIds) {
            return [];
        }
        $query = $this->createQueryBuilder('g')
            ->andWhere('g.enrollment IN (:enrollments)')->setParameter('enrollments', $enrollmentIds)
            ->andWhere('g.subject = :subject')->setParameter('subject', $subject)
            ->andWhere('g.gradingPeriod = :period')->setParameter('period', $period)
            ->getQuery();
        // Doctrine refreshes whenever the hint is set, whatever its value
        if ($fresh) {
            $query->setHint(Query::HINT_REFRESH, true);
        }
        /** @var list<Grade> $grades */
        $grades = $query->getResult();

        $byEnrollment = [];
        foreach ($grades as $grade) {
            $byEnrollment[(int) $grade->getEnrollment()->getId()] = $grade;
        }

        return $byEnrollment;
    }

    /**
     * Every subject's grades of these enrollments for the period.
     *
     * @param list<int> $enrollmentIds
     *
     * @return array<int, array<int, Grade>> keyed by enrollment id, then subject id
     */
    public function findForEnrollmentsByPeriod(array $enrollmentIds, GradingPeriod $period): array
    {
        if ([] === $enrollmentIds) {
            return [];
        }
        /** @var list<Grade> $grades */
        $grades = $this->createQueryBuilder('g')
            ->andWhere('g.enrollment IN (:enrollments)')->setParameter('enrollments', $enrollmentIds)
            ->andWhere('g.gradingPeriod = :period')->setParameter('period', $period)
            ->getQuery()->getResult();

        $byEnrollment = [];
        foreach ($grades as $grade) {
            $byEnrollment[(int) $grade->getEnrollment()->getId()][(int) $grade->getSubject()->getId()] = $grade;
        }

        return $byEnrollment;
    }

    /** Grades of the period in any school, which deleting the period would delete. */
    public function countForPeriod(GradingPeriod $period): int
    {
        return $this->count(['gradingPeriod' => $period]);
    }

    /** Grades of the enrollment in any subject and period. */
    public function countForEnrollment(Enrollment $enrollment): int
    {
        return $this->count(['enrollment' => $enrollment]);
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

    public function add(Grade $grade): void
    {
        $this->getEntityManager()->persist($grade);
    }

    public function remove(Grade $grade): void
    {
        $this->getEntityManager()->remove($grade);
    }
}
