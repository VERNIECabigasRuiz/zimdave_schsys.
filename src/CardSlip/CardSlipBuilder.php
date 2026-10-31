<?php

namespace App\CardSlip;

use App\Entity\GradingPeriod;
use App\Entity\Section;
use App\Grading\GridColumn;
use App\Grading\SectionGradeGrid;
use App\Repository\CardSlipCommentRepository;
use App\Repository\GradeLockRepository;
use App\Repository\SubjectRepository;

/**
 * Assembles each learner's card slip for a term from the section's grade grid, the term's
 * attendance and the adviser's comments (or the suggested ones when none are saved).
 */
class CardSlipBuilder
{
    public function __construct(
        private readonly SectionGradeGrid $grid,
        private readonly SubjectRepository $subjects,
        private readonly TermAttendance $attendance,
        private readonly CardSlipCommentRepository $comments,
        private readonly GradeLockRepository $locks,
        private readonly CardSlipSuggester $suggester,
    ) {
    }

    /** DO 15 descriptor of a term average, from the average rounded half-up to a whole number. */
    public static function descriptor(float $average): string
    {
        $rounded = (int) round($average);

        return match (true) {
            $rounded >= 90 => 'Advancing',
            $rounded >= 80 => 'Benchmarking',
            $rounded >= 75 => 'Connecting',
            $rounded >= 65 => 'Developing',
            default => 'Emerging',
        };
    }

    /** @return list<CardSlip> one per learner who can still be graded, in sheet order (males first) */
    public function forSection(Section $section, GradingPeriod $period): array
    {
        return $this->build($section, $period, null);
    }

    public function forEnrollment(Section $section, GradingPeriod $period, int $enrollmentId): ?CardSlip
    {
        return $this->build($section, $period, $enrollmentId)[0] ?? null;
    }

    /** @return list<CardSlip> */
    private function build(Section $section, GradingPeriod $period, ?int $onlyEnrollmentId): array
    {
        $grid = $this->grid->build($section, $period);
        $rows = array_values(array_filter(
            $grid['rows'],
            static fn (array $row) => $row['learner']->editable
                && (null === $onlyEnrollmentId || $row['learner']->enrollment->getId() === $onlyEnrollmentId),
        ));
        if ([] === $rows) {
            return [];
        }

        $componentKeys = [];
        foreach ($this->subjects->findForSection($section) as $subject) {
            if (null !== $subject->getParent()) {
                $componentKeys['s'.$subject->getId()] = true;
            }
        }

        $previousAverages = [];
        $previous = $period->getSchoolYear()->getGradingPeriod($period->getNumber() - 1);
        if (null !== $previous) {
            $previousGrid = $this->grid->build($section, $previous);
            foreach ($previousGrid['rows'] as $row) {
                $previousAverages[(int) $row['learner']->enrollment->getId()] = self::average(self::topLevelGrades($previousGrid['columns'], $row['cells'], $componentKeys));
            }
        }

        $ids = array_map(static fn (array $row) => (int) $row['learner']->enrollment->getId(), $rows);
        $attendance = $this->attendance->forSection($section, $period);
        $saved = $this->comments->findForEnrollments($ids, $period);
        $school = $section->getSchool();
        $draft = null === $this->locks->findFor($school, $period);
        $gradeSection = ltrim(($section->getGradeLevel()?->label() ?? '').' – '.mb_strtoupper($section->getName()), ' –');
        $adviserName = $section->getAdviser()?->getFullName() ?? '';

        $slips = [];
        foreach ($rows as $row) {
            $learner = $row['learner'];
            $id = (int) $learner->enrollment->getId();
            $grades = self::topLevelGrades($grid['columns'], $row['cells'], $componentKeys);
            $average = self::average($grades);
            $tally = $attendance[$id] ?? null;

            $facts = new TermFacts($grades, $average, $previousAverages[$id] ?? null, $tally?->absent, $tally?->late, $tally->schoolDays ?? 0);
            $suggested = $this->suggester->suggest($facts);
            $comment = $saved[$id] ?? null;
            // a saved row is used as it is, even with no codes; a code no longer in the catalog is skipped
            $chosen = null === $comment
                ? $suggested
                : array_values(array_filter($comment->getCodes(), static fn (string $code) => CardSlipComments::isKnown($code)));
            $customText = $comment?->getCustomText();

            $failing = $facts->failingSubjects();
            $texts = array_map(static fn (string $code) => CardSlipComments::text($code, $failing), $chosen);
            if (null !== $customText && '' !== trim($customText)) {
                $texts[] = trim($customText);
            }

            $slips[] = new CardSlip(
                schoolName: $school->getName(),
                schoolAddress: $school->getAddress(),
                logoPath: $school->getLogoPath(),
                periodLabel: $period->getLabel(),
                schoolYear: $section->getSchoolYear()->getName(),
                learnerName: $learner->name,
                lrn: $learner->lrn,
                gradeSection: $gradeSection,
                adviserName: $adviserName,
                lines: self::lines($grid['columns'], $row['cells'], $componentKeys),
                average: $average,
                descriptor: null === $average ? null : self::descriptor($average),
                attendance: $tally,
                comments: $texts,
                draft: $draft,
                enrollmentId: $id,
                suggestedCodes: $suggested,
                chosenCodes: $chosen,
                customText: $customText,
            );
        }

        return $slips;
    }

    /**
     * @param list<GridColumn>    $columns
     * @param array<string, ?int> $cells
     * @param array<string, true> $componentKeys
     *
     * @return list<CardSlipLine>
     */
    private static function lines(array $columns, array $cells, array $componentKeys): array
    {
        $lines = [];
        $components = [];
        foreach ($columns as $column) {
            $grade = $cells[$column->key] ?? null;
            if (isset($componentKeys[$column->key])) {
                // SectionGradeGrid puts a parent's components right before the parent's average column
                $components[] = $column->label.' '.($grade ?? '—');
                continue;
            }
            $lines[] = new CardSlipLine(
                $column->label,
                $grade,
                self::remarks($grade),
                $column->average && [] !== $components ? implode(' · ', $components) : null,
            );
            $components = [];
        }

        return $lines;
    }

    /**
     * @param list<GridColumn>    $columns
     * @param array<string, ?int> $cells
     * @param array<string, true> $componentKeys
     *
     * @return array<string, ?int> label => grade of every top-level subject (a parent once, as its average)
     */
    private static function topLevelGrades(array $columns, array $cells, array $componentKeys): array
    {
        $grades = [];
        foreach ($columns as $column) {
            if (!isset($componentKeys[$column->key])) {
                $grades[$column->label] = $cells[$column->key] ?? null;
            }
        }

        return $grades;
    }

    /** @param array<string, ?int> $grades null when there is no subject or one has no grade */
    private static function average(array $grades): ?float
    {
        if ([] === $grades || \in_array(null, $grades, true)) {
            return null;
        }

        return array_sum($grades) / \count($grades);
    }

    private static function remarks(?int $grade): string
    {
        return match (true) {
            null === $grade => '',
            $grade >= 75 => 'Passed',
            default => 'Failed',
        };
    }
}
