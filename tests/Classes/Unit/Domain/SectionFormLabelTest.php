<?php

declare(strict_types=1);

namespace App\Tests\Classes\Unit\Domain;

use App\Classes\Domain\Model\Section;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use PHPUnit\Framework\TestCase;

final class SectionFormLabelTest extends TestCase
{
    public function testPrintsTheGradeAndTheNameInCapitals(): void
    {
        // Initialize
        $graded = (new Section(new School(), new SchoolYear()))->setGradeLevel(GradeLevel::Grade8)->setName('Artistic');
        $ungraded = (new Section(new School(), new SchoolYear()))->setName('Niño');

        // Execute
        $labels = [$graded->getFormLabel(), $ungraded->getFormLabel()];

        // Assert
        self::assertSame(['Grade 8 – ARTISTIC', 'NIÑO'], $labels);
    }
}
