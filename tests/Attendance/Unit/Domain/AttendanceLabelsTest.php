<?php

declare(strict_types=1);

namespace App\Tests\Attendance\Unit\Domain;

use App\Attendance\Domain\Model\LearnerMark;
use App\Attendance\Domain\Service\Sf2\Sf2Status;
use PHPUnit\Framework\TestCase;

final class AttendanceLabelsTest extends TestCase
{
    public function testEachMarkHasItsSf2Code(): void
    {
        // Execute
        $codes = array_map(static fn (LearnerMark $mark) => $mark->code(), LearnerMark::cases());

        // Assert
        self::assertSame(['x', 'L', 'C'], $codes);
    }

    public function testEachSf2StatusHasALabel(): void
    {
        // Execute
        $labels = array_map(static fn (Sf2Status $status) => $status->label(), Sf2Status::cases());

        // Assert
        self::assertSame(['Not submitted', 'Submitted', 'Late'], $labels);
    }
}
