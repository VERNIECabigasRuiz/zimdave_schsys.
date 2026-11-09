<?php

declare(strict_types=1);

namespace App\Classes\Domain\Service\Period;

use App\Classes\Domain\Model\ClassPeriod;

/** A school's day of periods: their order and the label each one goes by. */
final class ClassPeriodDay
{
    /**
     * The periods in time order; equal start times keep the saved one first, a new one last.
     *
     * @param list<ClassPeriod> $periods
     *
     * @return list<ClassPeriod>
     */
    public static function sorted(array $periods): array
    {
        usort($periods, static fn (ClassPeriod $a, ClassPeriod $b) => [
            $a->getStartsAt()?->format('H:i:s'),
            $a->getId() ?? \PHP_INT_MAX,
        ] <=> [
            $b->getStartsAt()?->format('H:i:s'),
            $b->getId() ?? \PHP_INT_MAX,
        ]);

        return $periods;
    }

    /**
     * "Period 3" counting the class periods of the day up to this one, or the break's name.
     *
     * @param list<ClassPeriod> $day the school's periods, this one included
     */
    public static function label(ClassPeriod $period, array $day): string
    {
        if ($period->isBreak()) {
            return (string) $period->getBreakLabel();
        }
        $number = 0;
        foreach (self::sorted($day) as $other) {
            if (!$other->isBreak()) {
                ++$number;
            }
            if ($other === $period) {
                return 'Period ' . $number;
            }
        }

        return 'Period ?';
    }
}
