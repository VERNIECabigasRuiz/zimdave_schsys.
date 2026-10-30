<?php

namespace App\Nutrition;

use App\Attendance\Sf2Roster;
use App\Entity\NutritionAssessment;
use App\Entity\NutritionMeasurement;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Sf8Submission;
use App\Entity\User;
use App\Repository\NutritionAssessmentRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SectionRepository;
use App\Repository\Sf8SubmissionRepository;
use App\Service\SchoolClock;

/**
 * Computes a section's SF8 for a weighing period. The roster is the learners who count in the section
 * on the date of weighing; a measurement of anyone else (the date moved after it was saved) is ignored.
 */
class Sf8ReportBuilder
{
    /** The line under the school head's name; the app keeps no position for users. */
    public const PRINCIPAL_POSITION = 'School Principal';

    public function __construct(
        private readonly Sf2Roster $roster,
        private readonly NutritionClassifier $classifier,
        private readonly NutritionAssessmentRepository $assessments,
        private readonly Sf8SubmissionRepository $submissions,
        private readonly SectionRepository $sections,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly SchoolClock $clock,
    ) {
    }

    public function build(Section $section, NutritionPeriod $period): Sf8Report
    {
        return $this->report(
            $section,
            $period,
            $this->assessments->findFor($section, $period),
            $this->submissions->findFor($section, $period),
            $this->principal($section->getSchool(), $section->getSchoolYear()),
        );
    }

    /** @return list<Sf8Report> every section of the school year, by grade level, then name */
    public function buildForSchool(School $school, SchoolYear $schoolYear, NutritionPeriod $period): array
    {
        $sections = $this->sections->findForSchool($school, $schoolYear);
        $assessments = $this->assessments->findForSections($sections, $period);
        $submissions = $this->submissions->findForSections($sections, $period);
        $principal = $this->principal($school, $schoolYear);

        return array_map(fn (Section $section) => $this->report(
            $section,
            $period,
            $assessments[$section->getId()] ?? null,
            $submissions[$section->getId()] ?? null,
            $principal,
        ), $sections);
    }

    private function report(Section $section, NutritionPeriod $period, ?NutritionAssessment $assessment, ?Sf8Submission $submission, ?User $principal): Sf8Report
    {
        $weighedOn = ($assessment?->getWeighedOn() ?? $this->clock->now())->setTime(0, 0);

        /** @var array<int, NutritionMeasurement> $measurements by enrollment id */
        $measurements = [];
        foreach ($assessment?->getMeasurements() ?? [] as $measurement) {
            $measurements[(int) $measurement->getEnrollment()->getId()] = $measurement;
        }

        $rows = [];
        foreach ($this->roster->forMonth($section, $weighedOn) as $entry) {
            if (!$entry->counts($weighedOn)) {
                continue;
            }
            $learner = $entry->enrollment->getLearner();
            $measurement = $measurements[(int) $entry->enrollment->getId()] ?? null;
            $weighed = null !== $measurement && $measurement->isWeighed();
            $weightKg = self::toFloat($measurement?->getWeightKg());
            $heightM = self::toFloat($measurement?->getHeightM());
            $birthDate = $learner->getBirthDate()?->setTime(0, 0);

            $rows[] = new Sf8Row(
                $entry,
                '' !== $learner->getLrn() ? $learner->getLrn() : null,
                $birthDate,
                // a half-filled measurement is not classified
                $this->classifier->classify($entry->male, $birthDate, $weighedOn, $weighed ? $weightKg : null, $weighed ? $heightM : null),
                $weightKg,
                $heightM,
                $measurement?->getRemarks(),
                $weighed,
            );
        }

        $school = $section->getSchool();
        $adviser = $section->getAdviser();

        return new Sf8Report(
            section: $section,
            period: $period,
            weighedOn: $weighedOn,
            dateSet: null !== $assessment,
            rows: $rows,
            summary: Sf8Summary::fromRows($rows),
            status: match (true) {
                null !== $submission => Sf8Status::Submitted,
                null !== $assessment => Sf8Status::Draft,
                default => Sf8Status::NotStarted,
            },
            submission: $submission,
            adviserName: null !== $adviser ? mb_strtoupper($adviser->getFullName()) : null,
            principalName: null !== $principal ? mb_strtoupper($principal->getFullName()) : null,
            principalPosition: null !== $principal ? self::PRINCIPAL_POSITION : null,
            certifierName: $school->getSf8CertifierName(),
            certifierPosition: $school->getSf8CertifierPosition(),
        );
    }

    private function principal(School $school, SchoolYear $schoolYear): ?User
    {
        return $this->assignments->findPrincipal($school, $schoolYear)?->getUser();
    }

    private static function toFloat(?string $value): ?float
    {
        return null === $value ? null : (float) $value;
    }
}
