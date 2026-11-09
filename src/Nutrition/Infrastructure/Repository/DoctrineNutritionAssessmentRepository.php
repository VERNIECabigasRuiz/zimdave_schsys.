<?php

declare(strict_types=1);

namespace App\Nutrition\Infrastructure\Repository;

use App\Nutrition\Domain\Model\NutritionAssessment;
use App\Classes\Domain\Model\Section;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Nutrition\Domain\Model\Repository\NutritionAssessmentRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NutritionAssessment>
 */
class DoctrineNutritionAssessmentRepository extends ServiceEntityRepository implements
    NutritionAssessmentRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NutritionAssessment::class);
    }

    public function findFor(Section $section, NutritionPeriod $period): ?NutritionAssessment
    {
        /** @var NutritionAssessment|null */
        return $this->periodQuery($section, $period)->getOneOrNullResult();
    }

    public function findFresh(Section $section, NutritionPeriod $period): ?NutritionAssessment
    {
        /** @var NutritionAssessment|null */
        return $this->periodQuery($section, $period)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }

    public function add(NutritionAssessment $assessment): void
    {
        $this->getEntityManager()->persist($assessment);
    }

    /** @return Query<mixed, mixed> */
    private function periodQuery(Section $section, NutritionPeriod $period): Query
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.measurements', 'm')->addSelect('m')
            ->andWhere('a.section = :section')->setParameter('section', $section)
            ->andWhere('a.period = :period')->setParameter('period', $period)
            ->getQuery();
    }

    /**
     * @param list<Section> $sections
     *
     * @return array<int, NutritionAssessment> keyed by section id, with their measurements
     */
    public function findForSections(array $sections, NutritionPeriod $period): array
    {
        if ([] === $sections) {
            return [];
        }

        /** @var list<NutritionAssessment> $assessments */
        $assessments = $this->createQueryBuilder('a')
            ->leftJoin('a.measurements', 'm')->addSelect('m')
            ->andWhere('a.section IN (:sections)')->setParameter('sections', $sections)
            ->andWhere('a.period = :period')->setParameter('period', $period)
            ->getQuery()->getResult();

        $bySection = [];
        foreach ($assessments as $assessment) {
            $bySection[(int) $assessment->getSection()->getId()] = $assessment;
        }

        return $bySection;
    }
}
