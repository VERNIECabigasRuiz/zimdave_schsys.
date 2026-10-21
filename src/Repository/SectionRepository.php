<?php

namespace App\Repository;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Section>
 */
class SectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Section::class);
    }

    /** @return list<Section> by grade level, then name */
    public function findForSchool(School $school, SchoolYear $schoolYear): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.adviser', 'a')->addSelect('a')
            ->andWhere('s.school = :school')->setParameter('school', $school)
            ->andWhere('s.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->orderBy('s.gradeLevel', 'ASC')->addOrderBy('s.name', 'ASC')
            ->getQuery()->getResult();
    }

    /** The section this teacher advises in the school year, if any. */
    public function findAdvisory(User $teacher, SchoolYear $schoolYear): ?Section
    {
        return $this->findOneBy(['adviser' => $teacher, 'schoolYear' => $schoolYear]);
    }

    /**
     * Sections of $from that $to does not have yet (same grade level and name).
     *
     * @return list<Section>
     */
    public function findBringBackCandidates(School $school, SchoolYear $from, SchoolYear $to): array
    {
        // The name comparison runs in the database, so it follows the same collation
        // (case- and accent-insensitive) as the unique index: "Sto. Niño" = "Sto. Nino"
        $existing = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(Section::class, 'n')
            ->andWhere('n.school = :school')
            ->andWhere('n.schoolYear = :to')
            ->andWhere('n.gradeLevel = s.gradeLevel')
            ->andWhere('n.name = s.name');

        return $this->createQueryBuilder('s')
            ->andWhere('s.school = :school')->setParameter('school', $school)
            ->andWhere('s.schoolYear = :from')->setParameter('from', $from)
            ->andWhere('NOT EXISTS ('.$existing->getDQL().')')->setParameter('to', $to)
            ->orderBy('s.gradeLevel', 'ASC')->addOrderBy('s.name', 'ASC')
            ->getQuery()->getResult();
    }
}
