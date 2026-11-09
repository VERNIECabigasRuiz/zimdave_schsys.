<?php

declare(strict_types=1);

namespace App\Nutrition\Infrastructure\Repository;

use App\Classes\Domain\Model\Section;
use App\Nutrition\Domain\Model\Sf8Submission;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Nutrition\Domain\Model\Repository\Sf8SubmissionRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sf8Submission>
 */
class DoctrineSf8SubmissionRepository extends ServiceEntityRepository implements Sf8SubmissionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sf8Submission::class);
    }

    public function findFor(Section $section, NutritionPeriod $period): ?Sf8Submission
    {
        return $this->findOneBy(['section' => $section, 'period' => $period]);
    }

    public function findFresh(Section $section, NutritionPeriod $period): ?Sf8Submission
    {
        /** @var Sf8Submission|null */
        return $this->createQueryBuilder('f')
            ->andWhere('f.section = :section')->setParameter('section', $section)
            ->andWhere('f.period = :period')->setParameter('period', $period)
            ->getQuery()->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }

    public function add(Sf8Submission $submission): void
    {
        $this->getEntityManager()->persist($submission);
    }

    public function remove(Sf8Submission $submission): void
    {
        $this->getEntityManager()->remove($submission);
    }

    /**
     * @param list<Section> $sections
     *
     * @return array<int, Sf8Submission> keyed by section id
     */
    public function findForSections(array $sections, NutritionPeriod $period): array
    {
        if ([] === $sections) {
            return [];
        }

        /** @var list<Sf8Submission> $submissions */
        $submissions = $this->createQueryBuilder('f')
            ->andWhere('f.section IN (:sections)')->setParameter('sections', $sections)
            ->andWhere('f.period = :period')->setParameter('period', $period)
            ->getQuery()->getResult();

        $bySection = [];
        foreach ($submissions as $submission) {
            $bySection[(int) $submission->getSection()->getId()] = $submission;
        }

        return $bySection;
    }
}
