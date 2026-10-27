<?php

namespace App\Repository;

use App\Entity\ClassPeriod;
use App\Entity\School;
use App\Entity\ScheduleEntry;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\User;
use App\Entity\Weekday;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduleEntry>
 */
class ScheduleEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduleEntry::class);
    }

    /**
     * Entries of a section that run in a term (a full-year entry runs in both).
     *
     * @return list<ScheduleEntry>
     */
    public function findForSection(Section $section, int $term): array
    {
        return $this->withRelations()
            ->andWhere('e.section = :section')->setParameter('section', $section)
            ->andWhere('e.term IN (:terms)')->setParameter('terms', self::overlappingTerms($term))
            ->addOrderBy('e.day', 'ASC')->addOrderBy('e.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Entries of the school's other sections that run in a term (a full-year entry runs in both),
     * for the "teacher is busy" map.
     *
     * @return list<ScheduleEntry>
     */
    public function findBusy(School $school, SchoolYear $schoolYear, int $term, Section $exceptSection): array
    {
        return $this->createQueryBuilder('e')
            ->addSelect('l', 'sub', 'sec')
            ->join('e.load', 'l')->join('l.subject', 'sub')->join('e.section', 'sec')
            ->andWhere('sec.school = :school')->setParameter('school', $school)
            ->andWhere('e.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->andWhere('e.term IN (:terms)')->setParameter('terms', self::overlappingTerms($term))
            ->andWhere('e.section != :section')->setParameter('section', $exceptSection)
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<ScheduleEntry> */
    public function findForTeacher(User $teacher, SchoolYear $schoolYear): array
    {
        return $this->withRelations()
            ->andWhere('e.teacher = :teacher')->setParameter('teacher', $teacher)
            ->andWhere('e.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->addOrderBy('e.day', 'ASC')->addOrderBy('e.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<ScheduleEntry> */
    public function findForTeacherOn(User $teacher, SchoolYear $schoolYear, Weekday $day): array
    {
        return $this->withRelations()
            ->andWhere('e.teacher = :teacher')->setParameter('teacher', $teacher)
            ->andWhere('e.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->andWhere('e.day = :day')->setParameter('day', $day)
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function findTeacherClash(User $teacher, SchoolYear $schoolYear, Weekday $day, ClassPeriod $period, int $term, Section $exceptSection): ?ScheduleEntry
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.teacher = :teacher')->setParameter('teacher', $teacher)
            ->andWhere('e.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->andWhere('e.day = :day')->setParameter('day', $day)
            ->andWhere('e.period = :period')->setParameter('period', $period)
            ->andWhere('e.term IN (:terms)')->setParameter('terms', self::overlappingTerms($term))
            ->andWhere('e.section != :section')->setParameter('section', $exceptSection)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function findSectionClash(Section $section, Weekday $day, ClassPeriod $period, int $term): ?ScheduleEntry
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.section = :section')->setParameter('section', $section)
            ->andWhere('e.day = :day')->setParameter('day', $day)
            ->andWhere('e.period = :period')->setParameter('period', $period)
            ->andWhere('e.term IN (:terms)')->setParameter('terms', self::overlappingTerms($term))
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** Number of distinct sections that have classes in the period. */
    public function countForPeriod(ClassPeriod $period): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e.section)')
            ->andWhere('e.period = :period')->setParameter('period', $period)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<ScheduleEntry> */
    public function findAt(School $school, SchoolYear $schoolYear, Weekday $day, ClassPeriod $period): array
    {
        return $this->withRelations()
            ->andWhere('sec.school = :school')->setParameter('school', $school)
            ->andWhere('e.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->andWhere('e.day = :day')->setParameter('day', $day)
            ->andWhere('e.period = :period')->setParameter('period', $period)
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** Entries with their load, subject, section, period and teacher, read in the same query. */
    private function withRelations(): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->addSelect('l', 'sub', 'sec', 'p', 't')
            ->join('e.load', 'l')->join('l.subject', 'sub')->join('e.section', 'sec')
            ->join('e.period', 'p')->join('e.teacher', 't');
    }

    /**
     * 0 overlaps every term, 1 overlaps 0 and 1, 2 overlaps 0 and 2.
     *
     * @return list<int>
     */
    private static function overlappingTerms(int $term): array
    {
        return 0 === $term ? [0, 1, 2] : [0, $term];
    }
}
