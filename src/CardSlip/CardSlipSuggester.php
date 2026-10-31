<?php

namespace App\CardSlip;

final class CardSlipSuggester
{
    /** @return list<string> at most 3 comment codes */
    public function suggest(TermFacts $facts): array
    {
        $codes = [];
        $failing = $facts->failingSubjects();
        $average = $facts->average;

        if ($facts->isIncomplete()) {
            $codes[] = 'incomplete';
        } elseif ([] !== $failing) {
            $codes[] = 'needs_improvement';
        } elseif (null !== $average) {
            if ($average >= 90) {
                $codes[] = 'excellent';
            } elseif ($average >= 85) {
                $codes[] = 'very_good';
            } elseif ($average >= 80) {
                $codes[] = 'good';
            }
        }
        $academicAdded = [] !== $codes;

        if (null !== $facts->previousAverage && null !== $average && round($average - $facts->previousAverage, 2) >= 3) {
            $codes[] = 'improved';
        }

        $absences = $facts->absences;
        $tardies = $facts->tardies;
        if (null !== $absences && $absences >= 5) {
            $codes[] = 'absences';
        } elseif (null !== $tardies && $tardies >= 5) {
            $codes[] = 'tardiness';
        } elseif (0 === $absences && 0 === $tardies && $facts->schoolDays >= 1) {
            $codes[] = 'perfect_attendance';
        }

        if (\count($codes) < 3) {
            if ([] !== $failing || (null !== $absences && $absences >= 5)) {
                $codes[] = 'see_adviser';
            } elseif (!$academicAdded) {
                $codes[] = 'study_home';
            }
        }

        return \array_slice($codes, 0, 3);
    }
}
