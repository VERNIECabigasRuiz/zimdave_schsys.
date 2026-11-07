<?php

declare(strict_types=1);

namespace App\Tests\Classes\Unit\Domain;

use App\Classes\Domain\Model\ClassPeriod;
use App\Classes\Domain\Service\Period\ClassPeriodDay;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use PHPUnit\Framework\TestCase;

final class ClassPeriodDayTest extends TestCase
{
    private function period(string $start, ?int $id = null, ?string $break = null): ClassPeriod
    {
        $period = (new ClassPeriod(new School(), new SchoolYear()))
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt((new \DateTimeImmutable($start))->modify('+1 hour'))
            ->setBreakLabel($break);
        if (null !== $id) {
            (new \ReflectionProperty(ClassPeriod::class, 'id'))->setValue($period, $id);
        }

        return $period;
    }

    public function testSortsByStartTimeWithTheSavedPeriodFirstOnATie(): void
    {
        // Initialize
        $late = $this->period('09:00', 1);
        $new = $this->period('07:30');
        $saved = $this->period('07:30', 2);

        // Execute
        $sorted = ClassPeriodDay::sorted([$late, $new, $saved]);

        // Assert
        self::assertSame([$saved, $new, $late], $sorted);
    }

    public function testLabelsCountClassPeriodsAndNameBreaks(): void
    {
        // Initialize
        $first = $this->period('07:30', 1);
        $recess = $this->period('08:30', 2, 'Recess');
        $second = $this->period('08:50', 3);
        $day = [$second, $recess, $first];

        // Execute
        $labels = array_map(static fn (ClassPeriod $p) => ClassPeriodDay::label($p, $day), [$first, $recess, $second]);

        // Assert
        self::assertSame(['Period 1', 'Recess', 'Period 2'], $labels);
    }

    public function testAPeriodOutsideTheDayHasNoNumber(): void
    {
        // Initialize
        $outside = $this->period('10:00', 9);

        // Execute
        $label = ClassPeriodDay::label($outside, [$this->period('07:30', 1)]);

        // Assert
        self::assertSame('Period ?', $label);
    }
}
