<?php

namespace App\Repository;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Sf2Submission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sf2Submission>
 */
class Sf2SubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sf2Submission::class);
    }

    public function findOne(Section $section, \DateTimeImmutable $month): ?Sf2Submission
    {
        return $this->findOneBy(['section' => $section, 'month' => self::firstOf($month)]);
    }

    /** @return array<int, Sf2Submission> keyed by section id */
    public function findForMonth(School $school, SchoolYear $schoolYear, \DateTimeImmutable $month): array
    {
        $submissions = $this->createQueryBuilder('f')
            ->innerJoin('f.section', 's')
            ->andWhere('s.school = :school')->setParameter('school', $school)
            ->andWhere('s.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->andWhere('f.month = :month')->setParameter('month', self::firstOf($month), Types::DATE_IMMUTABLE)
            ->getQuery()->getResult();

        $bySection = [];
        foreach ($submissions as $submission) {
            $bySection[$submission->getSection()->getId()] = $submission;
        }

        return $bySection;
    }

    private static function firstOf(\DateTimeImmutable $month): \DateTimeImmutable
    {
        return $month->modify('first day of this month')->setTime(0, 0);
    }
}
