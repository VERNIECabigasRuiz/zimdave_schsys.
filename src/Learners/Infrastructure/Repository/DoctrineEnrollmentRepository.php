<?php

declare(strict_types=1);

namespace App\Learners\Infrastructure\Repository;

use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\SexAtBirth;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Enrollment>
 */
class DoctrineEnrollmentRepository extends ServiceEntityRepository implements EnrollmentRepositoryInterface
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
        /** @var list<array{sectionId: int|string, total: int|string}> $rows */
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

    /** @return list<Enrollment> boys first, then girls; each by last, first and middle name */
    public function findClassList(Section $section): array
    {
        /** @var list<Enrollment> */
        return $this->ordered($this->createQueryBuilder('e'))
            ->andWhere('e.section = :section')->setParameter('section', $section)
            ->getQuery()->getResult();
    }

    public function search(
        School $school,
        SchoolYear $schoolYear,
        ?string $query,
        ?Section $section,
        int $page = 1,
        int $perPage = 50,
    ): array {
        $qb = $this->ordered($this->createQueryBuilder('e'))
            ->andWhere('e.school = :school')->setParameter('school', $school)
            ->andWhere('e.schoolYear = :sy')->setParameter('sy', $schoolYear);
        if (null !== $section) {
            $qb->andWhere('e.section = :section')->setParameter('section', $section);
        }
        $query = null !== $query ? trim($query) : '';
        if ('' !== $query) {
            $qb->andWhere('l.lrn LIKE :q OR l.lastName LIKE :q OR l.firstName LIKE :q OR l.middleName LIKE :q')
                ->setParameter('q', '%' . addcslashes($query, '%_\\') . '%');
        }

        $qb->setFirstResult(max(0, $page - 1) * $perPage)->setMaxResults($perPage);

        // only to-one joins, so the rows are not multiplied
        $paginator = new Paginator($qb, false);
        /** @var list<Enrollment> $enrollments */
        $enrollments = iterator_to_array($paginator, false);

        return ['enrollments' => $enrollments, 'matches' => \count($paginator)];
    }

    public function findFresh(int $id): ?Enrollment
    {
        /** @var Enrollment|null */
        return $this->createQueryBuilder('e')
            ->where('e.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function add(Enrollment $enrollment): void
    {
        $this->getEntityManager()->persist($enrollment);
    }

    public function remove(Enrollment $enrollment): void
    {
        $this->getEntityManager()->remove($enrollment);
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
            ->addSelect('l', 'u')->innerJoin('e.learner', 'l')->innerJoin('l.user', 'u')
            ->addSelect('CASE WHEN l.sex = :male THEN 0 ELSE 1 END AS HIDDEN sexRank')
            ->setParameter('male', SexAtBirth::Male)
            ->addOrderBy('sexRank', 'ASC')
            ->addOrderBy('l.lastName', 'ASC')
            ->addOrderBy('l.firstName', 'ASC')
            ->addOrderBy('l.middleName', 'ASC')
            ->addOrderBy('e.id', 'ASC');
    }
}
