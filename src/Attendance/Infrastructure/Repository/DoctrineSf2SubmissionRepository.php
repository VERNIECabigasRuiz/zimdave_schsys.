<?php

declare(strict_types=1);

namespace App\Attendance\Infrastructure\Repository;

use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Attendance\Domain\Model\Repository\Sf2SubmissionRepositoryInterface;
use App\Attendance\Domain\Model\Sf2Submission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sf2Submission>
 */
class DoctrineSf2SubmissionRepository extends ServiceEntityRepository implements Sf2SubmissionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sf2Submission::class);
    }

    public function findOne(Section $section, \DateTimeImmutable $month): ?Sf2Submission
    {
        return $this->findOneBy(['section' => $section, 'month' => self::firstOf($month)]);
    }

    public function findFresh(Section $section, \DateTimeImmutable $month): ?Sf2Submission
    {
        /** @var Sf2Submission|null */
        return $this->createQueryBuilder('f')
            ->andWhere('f.section = :section')->setParameter('section', $section)
            ->andWhere('f.month = :month')->setParameter('month', self::firstOf($month), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function add(Sf2Submission $submission): void
    {
        $this->getEntityManager()->persist($submission);
    }

    public function remove(Sf2Submission $submission): void
    {
        $this->getEntityManager()->remove($submission);
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
        /** @var list<Sf2Submission> $submissions */
        foreach ($submissions as $submission) {
            $bySection[(int) $submission->getSection()->getId()] = $submission;
        }

        return $bySection;
    }

    private static function firstOf(\DateTimeImmutable $month): \DateTimeImmutable
    {
        return $month->modify('first day of this month')->setTime(0, 0);
    }
}
