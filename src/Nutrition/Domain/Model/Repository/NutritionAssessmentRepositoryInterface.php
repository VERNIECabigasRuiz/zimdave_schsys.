<?php

declare(strict_types=1);

namespace App\Nutrition\Domain\Model\Repository;

use App\Classes\Domain\Model\Section;
use App\Nutrition\Domain\Model\NutritionAssessment;
use App\Nutrition\Domain\Model\NutritionPeriod;

interface NutritionAssessmentRepositoryInterface
{
    /** The section's weighing for the period, with its measurements. */
    public function findFor(Section $section, NutritionPeriod $period): ?NutritionAssessment;

    /** The same, read again from the database even when already loaded. */
    public function findFresh(Section $section, NutritionPeriod $period): ?NutritionAssessment;

    /**
     * @param list<Section> $sections
     *
     * @return array<int, NutritionAssessment> keyed by section id, with their measurements
     */
    public function findForSections(array $sections, NutritionPeriod $period): array;

    public function add(NutritionAssessment $assessment): void;
}
