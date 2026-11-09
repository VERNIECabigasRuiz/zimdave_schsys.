<?php

declare(strict_types=1);

namespace App\Tests\Nutrition\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Nutrition\Application\Service\NutritionRecorder;
use App\Nutrition\Application\UseCase\Command\MeasurementInput;
use App\Nutrition\Domain\Model\NutritionAssessment;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Nutrition\Domain\Model\Sf8Submission;
use Doctrine\ORM\EntityManagerInterface;

/** Calls the nutrition recorder as a use case would, saving after each call that succeeds. */
final class SavingNutritionRecorder
{
    public function __construct(
        private readonly NutritionRecorder $recorder,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param list<MeasurementInput> $inputs */
    public function save(
        Section $section,
        NutritionPeriod $period,
        \DateTimeImmutable $weighedOn,
        array $inputs,
        User $by,
    ): NutritionAssessment {
        $assessment = $this->recorder->save($section, $period, $weighedOn, $inputs, $by);
        $this->em->flush();

        return $assessment;
    }

    public function submit(Section $section, NutritionPeriod $period, User $by, bool $confirmUnweighed): Sf8Submission
    {
        $submission = $this->recorder->submit($section, $period, $by, $confirmUnweighed);
        $this->em->flush();

        return $submission;
    }

    public function reopen(Section $section, NutritionPeriod $period): void
    {
        $this->recorder->reopen($section, $period);
        $this->em->flush();
    }
}
