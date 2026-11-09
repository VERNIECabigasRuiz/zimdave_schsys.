<?php

declare(strict_types=1);

namespace App\Classes\Infrastructure\Repository;

use App\Classes\Domain\Model\Repository\TeachingLoadRepositoryInterface;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Access\Domain\Model\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeachingLoad>
 */
class DoctrineTeachingLoadRepository extends ServiceEntityRepository implements TeachingLoadRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeachingLoad::class);
    }

    /** @return list<TeachingLoad> */
    public function findForSection(Section $section): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.section = :section')->setParameter('section', $section)
            ->getQuery()->getResult();
    }

    public function findOne(Section $section, Subject $subject): ?TeachingLoad
    {
        return $this->findOneBy(['section' => $section, 'subject' => $subject]);
    }

    /** Loads of the subject, or of its components. */
    public function countForSubject(Subject $subject): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->join('l.subject', 's')
            ->andWhere('s = :subject OR s.parent = :subject')->setParameter('subject', $subject)
            ->getQuery()->getSingleScalarResult();
    }

    /** Loads of the subject itself, not of its components. */
    public function countOwnForSubject(Subject $subject): int
    {
        return $this->count(['subject' => $subject]);
    }

    public function countForTeacher(User $teacher, SchoolYear $schoolYear): int
    {
        return (int) $this->forTeacher($teacher, $schoolYear)->select('COUNT(l.id)')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<TeachingLoad> */
    public function findForTeacher(User $teacher, SchoolYear $schoolYear): array
    {
        return $this->forTeacher($teacher, $schoolYear)->getQuery()->getResult();
    }

    private function forTeacher(User $teacher, SchoolYear $schoolYear): QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->join('l.section', 'sec')
            ->andWhere('l.teacher = :teacher')->setParameter('teacher', $teacher)
            ->andWhere('sec.schoolYear = :sy')->setParameter('sy', $schoolYear);
    }

    public function findById(int $id): ?TeachingLoad
    {
        return $this->find($id);
    }

    public function add(TeachingLoad $load): void
    {
        $this->getEntityManager()->persist($load);
    }

    public function remove(TeachingLoad $load): void
    {
        $this->getEntityManager()->remove($load);
    }
}
