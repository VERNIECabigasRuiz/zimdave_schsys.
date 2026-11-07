<?php

declare(strict_types=1);

namespace App\Classes\Infrastructure\Repository;

use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subject>
 */
class DoctrineSubjectRepository extends ServiceEntityRepository implements SubjectRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subject::class);
    }

    /** @return list<Subject> by grade level, semester, position, name (components included) */
    public function findForSchool(School $school, SchoolYear $schoolYear): array
    {
        /** @var list<Subject> */
        return $this->ordered($this->createQueryBuilder('s')
            ->andWhere('s.school = :school')->setParameter('school', $school)
            ->andWhere('s.schoolYear = :sy')->setParameter('sy', $schoolYear))
            ->getQuery()->getResult();
    }

    /**
     * The subjects a section takes: its grade level's subjects that are not narrowed
     * to another track or strand.
     *
     * @return list<Subject>
     */
    public function findForSection(Section $section): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.school = :school')->setParameter('school', $section->getSchool())
            ->andWhere('s.schoolYear = :sy')->setParameter('sy', $section->getSchoolYear())
            ->andWhere('s.gradeLevel = :grade')->setParameter('grade', $section->getGradeLevel());
        null === $section->getTrack()
            ? $qb->andWhere('s.track IS NULL')
            : $qb->andWhere('s.track IS NULL OR s.track = :track')->setParameter('track', $section->getTrack());
        null === $section->getStrand()
            ? $qb->andWhere('s.strand IS NULL')
            : $qb->andWhere('s.strand IS NULL OR LOWER(s.strand) = :strand')->setParameter(
                'strand',
                mb_strtolower($section->getStrand()),
            );

        /** @var list<Subject> */
        return $this->ordered($qb)->getQuery()->getResult();
    }

    /** Where a new subject of this grade goes on the report card. */
    public function nextPosition(School $school, SchoolYear $schoolYear, GradeLevel $grade): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->andWhere('s.school = :school')->setParameter('school', $school)
            ->andWhere('s.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->andWhere('s.gradeLevel = :grade')->setParameter('grade', $grade)
            ->getQuery()->getSingleScalarResult();

        return (int) $max + 1;
    }

    /**
     * Top-level subjects of $from that $to has neither by code nor by grade, semester and name.
     *
     * @return list<Subject>
     */
    public function findBringBackCandidates(School $school, SchoolYear $from, SchoolYear $to): array
    {
        // Compared in the database, so it follows the same case-insensitive collation as the code index
        $existing = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(Subject::class, 'n')
            ->andWhere('n.school = :school')
            ->andWhere('n.schoolYear = :to')
            ->andWhere('n.code = s.code OR (n.gradeLevel = s.gradeLevel AND n.name = s.name'
                . ' AND (n.semester = s.semester OR (n.semester IS NULL AND s.semester IS NULL)))');

        /** @var list<Subject> */
        return $this->ordered($this->createQueryBuilder('s')
            ->andWhere('s.school = :school')->setParameter('school', $school)
            ->andWhere('s.schoolYear = :from')->setParameter('from', $from)
            ->andWhere('s.parent IS NULL')
            ->andWhere('NOT EXISTS (' . $existing->getDQL() . ')')->setParameter('to', $to))
            ->getQuery()->getResult();
    }

    private function ordered(QueryBuilder $qb): QueryBuilder
    {
        return $qb->orderBy('s.gradeLevel', 'ASC')->addOrderBy('s.semester', 'ASC')
            ->addOrderBy('s.position', 'ASC')->addOrderBy('s.name', 'ASC');
    }

    public function findById(int $id): ?Subject
    {
        return $this->find($id);
    }

    public function findOneByCode(School $school, SchoolYear $schoolYear, string $code): ?Subject
    {
        return $this->findOneBy(['school' => $school, 'schoolYear' => $schoolYear, 'code' => $code]);
    }

    public function findOneByGradeAndName(
        School $school,
        SchoolYear $schoolYear,
        GradeLevel $gradeLevel,
        ?int $semester,
        string $name,
    ): ?Subject {
        // findOneBy turns a null semester into IS NULL, so K–10 names are compared too
        return $this->findOneBy([
            'school' => $school,
            'schoolYear' => $schoolYear,
            'gradeLevel' => $gradeLevel,
            'semester' => $semester,
            'name' => $name,
        ]);
    }

    public function add(Subject $subject): void
    {
        $this->getEntityManager()->persist($subject);
    }

    public function remove(Subject $subject): void
    {
        $this->getEntityManager()->remove($subject);
    }
}
