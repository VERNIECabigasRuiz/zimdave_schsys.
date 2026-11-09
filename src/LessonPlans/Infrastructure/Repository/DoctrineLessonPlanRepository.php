<?php

declare(strict_types=1);

namespace App\LessonPlans\Infrastructure\Repository;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\TeachingLoad;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\LessonPlans\Domain\Model\Repository\LessonPlanRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LessonPlan>
 */
class DoctrineLessonPlanRepository extends ServiceEntityRepository implements LessonPlanRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LessonPlan::class);
    }

    public function findById(int $id): ?LessonPlan
    {
        return $this->find($id);
    }

    public function findForTeacher(User $teacher, SchoolYear $schoolYear): array
    {
        /** @var list<LessonPlan> */
        return $this->bySchoolYear($schoolYear)
            ->andWhere('l.teacher = :teacher')->setParameter('teacher', $teacher)
            ->getQuery()->getResult();
    }

    public function findForSchool(School $school, SchoolYear $schoolYear): array
    {
        /** @var list<LessonPlan> */
        return $this->bySchoolYear($schoolYear)
            ->andWhere('sec.school = :school')->setParameter('school', $school)
            ->getQuery()->getResult();
    }

    public function findOneFor(TeachingLoad $load, \DateTimeImmutable $weekStart): ?LessonPlan
    {
        return $this->findOneBy(['load' => $load, 'weekStart' => $weekStart->setTime(0, 0)]);
    }

    public function add(LessonPlan $plan): void
    {
        $this->getEntityManager()->persist($plan);
    }

    public function remove(LessonPlan $plan): void
    {
        $this->getEntityManager()->remove($plan);
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
