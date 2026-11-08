<?php

declare(strict_types=1);

namespace App\Nutrition\Application\Service;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Learners\Application\Api\SectionRosterInterface;
use App\Nutrition\Application\UseCase\Command\MeasurementInput;
use App\Nutrition\Domain\Exception\NutritionException;
use App\Nutrition\Domain\Model\NutritionAssessment;
use App\Nutrition\Domain\Model\NutritionMeasurement;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Nutrition\Domain\Model\Repository\NutritionAssessmentRepositoryInterface;
use App\Nutrition\Domain\Model\Repository\Sf8SubmissionRepositoryInterface;
use App\Nutrition\Domain\Model\Sf8Submission;
use App\Nutrition\Domain\Service\Sf8\Sf8Row;

/**
 * Checks and applies weighings and SF8 submissions. It neither locks nor saves: its use cases take the
 * section's lock first and save when they end.
 */
class NutritionRecorder
{
    /** The lock a section's nutrition changes take, followed by the section id. */
    public const LOCK = 'nutrition-section-';
    public const BUSY = 'Another change is being saved. Please try again.';

    public function __construct(
        private readonly NutritionRules $rules,
        private readonly SectionRosterInterface $roster,
        private readonly Sf8ReportBuilder $reports,
        private readonly NutritionAssessmentRepositoryInterface $assessments,
        private readonly Sf8SubmissionRepositoryInterface $submissions,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /**
     * Records the date of weighing and the learners' measurements, replacing earlier ones. Inputs for
     * learners who don't count on the date are ignored; an input with nothing in it removes the measurement.
     *
     * @param list<MeasurementInput> $inputs
     *
     * @throws NutritionException
     */
    public function save(
        Section $section,
        NutritionPeriod $period,
        \DateTimeImmutable $weighedOn,
        array $inputs,
        User $by,
    ): NutritionAssessment {
        $weighedOn = $weighedOn->setTime(0, 0);
        if ($this->rules->isLocked($section, $period)) {
            throw new NutritionException(\sprintf('%s is submitted and locked.', $period->label()));
        }
        if (null !== $refusal = $this->rules->refusalForDate($section, $period, $weighedOn)) {
            throw new NutritionException($refusal);
        }

        $now = $this->clock->now();
        $assessment = $this->assessments->findFresh($section, $period);
        if (null === $assessment) {
            $assessment = new NutritionAssessment($section, $period, $weighedOn, $by, $now);
            $this->assessments->add($assessment);
            $action = 'Weighed %s for %s';
        } else {
            $assessment->setWeighedOn($weighedOn)->touch($now);
            $action = 'Updated weighing of %s for %s';
        }

        $byId = [];
        foreach ($inputs as $input) {
            $byId[$input->enrollmentId] = $input;
        }
        foreach ($this->roster->forMonth($section, $weighedOn) as $entry) {
            $id = (int) $entry->enrollment->getId();
            if (!$entry->counts($weighedOn) || !isset($byId[$id])) {
                continue;
            }
            $input = $byId[$id];
            $remarks = null !== $input->remarks ? trim($input->remarks) : null;
            $remarks = '' === $remarks ? null : $remarks;
            $measurement = $assessment->measurementFor($entry->enrollment);
            if (null === $input->weightKg && null === $input->heightM && null === $remarks) {
                if (null !== $measurement) {
                    $assessment->removeMeasurement($measurement);
                }
                continue;
            }
            if (null === $measurement) {
                $measurement = new NutritionMeasurement($assessment, $entry->enrollment);
                $assessment->addMeasurement($measurement);
            }
            $measurement
                ->setWeightKg(null !== $input->weightKg ? number_format($input->weightKg, 2, '.', '') : null)
                ->setHeightM(null !== $input->heightM ? number_format($input->heightM, 2, '.', '') : null)
                ->setRemarks($remarks);
        }

        $this->activityLogger->log(\sprintf($action, $section->getFormLabel(), $period->label()));

        return $assessment;
    }

    /**
     * Hands the SF8 to the principal, which locks the period.
     *
     * @param bool $confirmUnweighed the adviser confirmed that learners without weight and height stay unweighed
     *
     * @throws NutritionException
     */
    public function submit(Section $section, NutritionPeriod $period, User $by, bool $confirmUnweighed): Sf8Submission
    {
        if ($this->rules->isLocked($section, $period)) {
            throw new NutritionException(\sprintf('%s is already submitted.', $period->label()));
        }
        if (null === $this->assessments->findFresh($section, $period)) {
            throw new NutritionException('Set the date of weighing and save first.');
        }
        $unweighed = \count(array_filter(
            $this->reports->build($section, $period)->rows,
            static fn (Sf8Row $row) => !$row->weighed,
        ));
        if ($unweighed > 0 && !$confirmUnweighed) {
            throw new NutritionException(\sprintf(
                '%d %s no weight and height. Confirm to submit anyway.',
                $unweighed,
                1 === $unweighed ? 'learner has' : 'learners have',
            ));
        }

        $submission = new Sf8Submission($section, $period, $by, $this->clock->now());
        $this->submissions->add($submission);
        $this->activityLogger->log(
            \sprintf('Submitted SF8 of %s for %s', $section->getFormLabel(), $period->label()),
        );

        return $submission;
    }

    /** @throws NutritionException */
    public function reopen(Section $section, NutritionPeriod $period): void
    {
        $submission = $this->submissions->findFresh($section, $period);
        if (null === $submission) {
            throw new NutritionException(\sprintf('%s is not submitted.', $period->label()));
        }
        $this->submissions->remove($submission);
        $this->activityLogger->log(
            \sprintf('Reopened SF8 of %s for %s', $section->getFormLabel(), $period->label()),
        );
    }
}
