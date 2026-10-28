<?php

namespace App\Repository;

use App\Entity\Enrollment;
use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Enrollment>
 */
class EnrollmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Enrollment::class);
    }

    public function findCurrent(Learner $learner, SchoolYear $schoolYear): ?Enrollment
    {
        return $this->findOneBy(['learner' => $learner, 'schoolYear' => $schoolYear]);
    }

    public function countForSchool(School $school, SchoolYear $schoolYear): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.school = :school')->setParameter('school', $school)
            ->andWhere('e.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->getQuery()->getSingleScalarResult();
    }

    public function countForSection(Section $section): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.section = :section')->setParameter('section', $section)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return array<int, int> section id => number of learners */
    public function countBySection(School $school, SchoolYear $schoolYear): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.section) AS sectionId, COUNT(e.id) AS total')
            ->andWhere('e.school = :school')->setParameter('school', $school)
            ->andWhere('e.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->groupBy('e.section')
            ->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['sectionId']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return list<Enrollment> boys first, then girls; each by last name, first name */
    public function findClassList(Section $section): array
    {
        return $this->ordered($this->createQueryBuilder('e'))
            ->andWhere('e.section = :section')->setParameter('section', $section)
            ->getQuery()->getResult();
    }

    /** @return list<Enrollment> */
    public function search(School $school, SchoolYear $schoolYear, ?string $query, ?Section $section): array
    {
        $qb = $this->ordered($this->createQueryBuilder('e'))
            ->andWhere('e.school = :school')->setParameter('school', $school)
            ->andWhere('e.schoolYear = :sy')->setParameter('sy', $schoolYear);
        if (null !== $section) {
            $qb->andWhere('e.section = :section')->setParameter('section', $section);
        }
        $query = null !== $query ? trim($query) : '';
        if ('' !== $query) {
            $qb->andWhere('l.lrn LIKE :q OR l.lastName LIKE :q OR l.firstName LIKE :q OR l.middleName LIKE :q')
                ->setParameter('q', '%'.addcslashes($query, '%_\\').'%');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Enrollment> newest school year first */
    public function findHistory(Learner $learner): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.schoolYear', 'sy')
            ->andWhere('e.learner = :learner')->setParameter('learner', $learner)
            ->orderBy('sy.startDate', 'DESC')
            ->getQuery()->getResult();
    }

    private function ordered(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->addSelect('l')->innerJoin('e.learner', 'l')
            ->addSelect('CASE WHEN l.sex = :male THEN 0 ELSE 1 END AS HIDDEN sexRank')
            ->setParameter('male', SexAtBirth::Male)
            ->addOrderBy('sexRank', 'ASC')
            ->addOrderBy('l.lastName', 'ASC')
            ->addOrderBy('l.firstName', 'ASC');
    }
}
