<?php

declare(strict_types=1);

namespace App\Nutrition\Domain\Model\Repository;

use App\Classes\Domain\Model\Section;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Nutrition\Domain\Model\Sf8Submission;

interface Sf8SubmissionRepositoryInterface
{
    public function findFor(Section $section, NutritionPeriod $period): ?Sf8Submission;

    /** The same, read again from the database even when already loaded. */
    public function findFresh(Section $section, NutritionPeriod $period): ?Sf8Submission;

    /**
     * @param list<Section> $sections
     *
     * @return array<int, Sf8Submission> keyed by section id
     */
    public function findForSections(array $sections, NutritionPeriod $period): array;

    public function add(Sf8Submission $submission): void;

    public function remove(Sf8Submission $submission): void;
}
