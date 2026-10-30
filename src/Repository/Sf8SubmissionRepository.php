<?php

namespace App\Repository;

use App\Entity\Section;
use App\Entity\Sf8Submission;
use App\Nutrition\NutritionPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sf8Submission>
 */
class Sf8SubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sf8Submission::class);
    }

    public function findFor(Section $section, NutritionPeriod $period): ?Sf8Submission
    {
        return $this->findOneBy(['section' => $section, 'period' => $period]);
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

        $submissions = $this->createQueryBuilder('f')
            ->andWhere('f.section IN (:sections)')->setParameter('sections', $sections)
            ->andWhere('f.period = :period')->setParameter('period', $period)
            ->getQuery()->getResult();

        $bySection = [];
        foreach ($submissions as $submission) {
            $bySection[$submission->getSection()->getId()] = $submission;
        }

        return $bySection;
    }
}
