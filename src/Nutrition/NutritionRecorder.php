<?php

namespace App\Nutrition;

use App\Attendance\AttendanceRecorder;
use App\Attendance\Sf2Roster;
use App\Entity\NutritionAssessment;
use App\Entity\NutritionMeasurement;
use App\Entity\Section;
use App\Entity\Sf8Submission;
use App\Entity\User;
use App\Repository\NutritionAssessmentRepository;
use App\Repository\Sf8SubmissionRepository;
use App\Service\ActivityLogger;
use App\Service\LearnerLock;
use App\Service\SchoolClock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/** Saves weighings and SF8 submissions. A section's nutrition data changes one request at a time, under a lock. */
class NutritionRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly NutritionRules $rules,
        private readonly Sf2Roster $roster,
        private readonly Sf8ReportBuilder $reports,
        private readonly NutritionAssessmentRepository $assessments,
        private readonly Sf8SubmissionRepository $submissions,
        private readonly ActivityLogger $activityLogger,
        private readonly SchoolClock $clock,
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
    public function save(Section $section, NutritionPeriod $period, \DateTimeImmutable $weighedOn, array $inputs, User $by): NutritionAssessment
    {
        $weighedOn = $weighedOn->setTime(0, 0);

        return $this->locked($section, function () use ($section, $period, $weighedOn, $inputs, $by): NutritionAssessment {
            if ($this->rules->isLocked($section, $period)) {
                throw new NutritionException(\sprintf('%s is submitted and locked.', $period->label()));
            }
            if (null !== $refusal = $this->rules->refusalForDate($section, $period, $weighedOn)) {
                throw new NutritionException($refusal);
            }

            $now = $this->clock->now();
            $assessment = $this->assessments->findFor($section, $period);
            if (null === $assessment) {
                $assessment = new NutritionAssessment($section, $period, $weighedOn, $by, $now);
                $this->entityManager->persist($assessment);
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

            $this->activityLogger->log(\sprintf($action, AttendanceRecorder::label($section), $period->label()));
            $this->entityManager->flush();

            return $assessment;
        });
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
        return $this->locked($section, function () use ($section, $period, $by, $confirmUnweighed): Sf8Submission {
            if ($this->rules->isLocked($section, $period)) {
                throw new NutritionException(\sprintf('%s is already submitted.', $period->label()));
            }
            if (null === $this->assessments->findFor($section, $period)) {
                throw new NutritionException('Set the date of weighing and save first.');
            }
            $unweighed = \count(array_filter($this->reports->build($section, $period)->rows, static fn (Sf8Row $row) => !$row->weighed));
            if ($unweighed > 0 && !$confirmUnweighed) {
                throw new NutritionException(\sprintf('%d %s no weight and height. Confirm to submit anyway.', $unweighed, 1 === $unweighed ? 'learner has' : 'learners have'));
            }

            $submission = new Sf8Submission($section, $period, $by, $this->clock->now());
            $this->entityManager->persist($submission);
            $this->activityLogger->log(\sprintf('Submitted SF8 of %s for %s', AttendanceRecorder::label($section), $period->label()));
            $this->entityManager->flush();

            return $submission;
        });
    }

    /** @throws NutritionException */
    public function reopen(Section $section, NutritionPeriod $period): void
    {
        $this->locked($section, function () use ($section, $period): void {
            $submission = $this->submissions->findFor($section, $period);
            if (null === $submission) {
                throw new NutritionException(\sprintf('%s is not submitted.', $period->label()));
            }
            $this->entityManager->remove($submission);
            $this->activityLogger->log(\sprintf('Reopened SF8 of %s for %s', AttendanceRecorder::label($section), $period->label()));
            $this->entityManager->flush();
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     */
    private function locked(Section $section, callable $change): mixed
    {
        $lock = $this->lockFactory->createLock('nutrition-section-'.$section->getId());
        if (!$lock->acquire()) {
            throw new NutritionException(LearnerLock::BUSY);
        }

        try {
            return $change();
        } finally {
            $lock->release();
        }
    }
}
