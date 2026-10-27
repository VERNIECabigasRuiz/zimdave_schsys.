<?php

namespace App\Repository;

use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeachingLoad>
 */
class TeachingLoadRepository extends ServiceEntityRepository
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
}
