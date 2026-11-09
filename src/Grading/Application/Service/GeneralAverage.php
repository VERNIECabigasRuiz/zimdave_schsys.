<?php

declare(strict_types=1);

namespace App\Grading\Application\Service;

use App\Classes\Domain\Model\Subject;

/**
 * A learner's general average for a term, as the card slip prints it: the mean of the top-level subjects'
 * grades from the section's grade grid, a parent subject counted once as its average.
 */
final class GeneralAverage
{
    public const PASSING = 75;

    /**
     * @param list<Subject> $subjects the section's subjects
     *
     * @return array<string, true> grid keys of the component columns, which the average leaves out
     */
    public static function componentKeys(array $subjects): array
    {
        $keys = [];
        foreach ($subjects as $subject) {
            if (null !== $subject->getParent()) {
                $keys['s' . $subject->getId()] = true;
            }
        }

        return $keys;
    }

    /**
     * @param list<GridColumn>    $columns
     * @param array<string, ?int> $cells
     * @param array<string, true> $componentKeys
     *
     * @return array<string, ?int> label => grade of every top-level subject (a parent once, as its average)
     */
    public static function topLevelGrades(array $columns, array $cells, array $componentKeys): array
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
    public static function of(array $grades): ?float
    {
        if ([] === $grades || \in_array(null, $grades, true)) {
            return null;
        }

        return array_sum($grades) / \count($grades);
    }

    /** Below 75 once rounded half-up to a whole number, the same rounding as the card slip's descriptor. */
    public static function isFailing(float $average): bool
    {
        return round($average) < self::PASSING;
    }
}
