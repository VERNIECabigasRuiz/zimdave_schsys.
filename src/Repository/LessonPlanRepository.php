<?php

namespace App\Repository;

use App\Entity\LessonPlan;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\TeachingLoad;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LessonPlan>
 */
class LessonPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LessonPlan::class);
    }

    /** @return list<LessonPlan> newest week first */
    public function findForTeacher(User $teacher, SchoolYear $schoolYear): array
    {
        return $this->bySchoolYear($schoolYear)
            ->andWhere('l.teacher = :teacher')->setParameter('teacher', $teacher)
            ->getQuery()->getResult();
    }

    /** @return list<LessonPlan> newest week first */
    public function findForSchool(School $school, SchoolYear $schoolYear): array
    {
        return $this->bySchoolYear($schoolYear)
            ->andWhere('sec.school = :school')->setParameter('school', $school)
            ->getQuery()->getResult();
    }

    public function findOneFor(TeachingLoad $load, \DateTimeImmutable $weekStart): ?LessonPlan
    {
        return $this->findOneBy(['load' => $load, 'weekStart' => $weekStart->setTime(0, 0)]);
    }

    private function bySchoolYear(SchoolYear $schoolYear): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->join('p.load', 'l')->addSelect('l')
            ->join('l.section', 'sec')->addSelect('sec')
            ->join('l.subject', 'sub')->addSelect('sub')
            ->andWhere('sec.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->orderBy('p.weekStart', 'DESC')->addOrderBy('sec.name', 'ASC');
    }
}
