<?php

namespace App\Repository;

use App\Entity\NutritionAssessment;
use App\Entity\Section;
use App\Nutrition\NutritionPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NutritionAssessment>
 */
class NutritionAssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NutritionAssessment::class);
    }

    public function findFor(Section $section, NutritionPeriod $period): ?NutritionAssessment
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.measurements', 'm')->addSelect('m')
            ->andWhere('a.section = :section')->setParameter('section', $section)
            ->andWhere('a.period = :period')->setParameter('period', $period)
            ->getQuery()->getOneOrNullResult();
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

        $assessments = $this->createQueryBuilder('a')
            ->leftJoin('a.measurements', 'm')->addSelect('m')
            ->andWhere('a.section IN (:sections)')->setParameter('sections', $sections)
            ->andWhere('a.period = :period')->setParameter('period', $period)
            ->getQuery()->getResult();

        $bySection = [];
        foreach ($assessments as $assessment) {
            $bySection[$assessment->getSection()->getId()] = $assessment;
        }

        return $bySection;
    }
}
