<?php

declare(strict_types=1);

namespace App\Tests\Grading\Unit\Application;

use App\Grading\Application\UseCase\Command\DeclineGradeChangeCommand;
use App\Grading\Application\UseCase\Command\RequestGradeChangeCommand;
use App\Grading\Application\UseCase\Command\SaveCardSlipCommentsCommand;
use PHPUnit\Framework\TestCase;

final class GradingCommandLogTest extends TestCase
{
    public function testTypedTextIsNotLogged(): void
    {
        // Initialize
        $commands = [
            new SaveCardSlipCommentsCommand(
                1,
                2,
                [10 => ['codes' => ['excellent'], 'text' => 'Shy in class, needs support at home.']],
                [10 => ['excellent'], 11 => ['participates']],
            ),
            new RequestGradeChangeCommand(1, 2, 3, 4, 5, 6, [10 => 82, 11 => 90], 'Encoding error on the second quiz.'),
            new DeclineGradeChangeCommand(1, 4, 7, 'Attach the class record.'),
        ];

        // Execute
        $logged = array_map(static fn ($command) => $command->toLogArray(), $commands);

        // Assert
        self::assertSame([
            ['actorId' => 1, 'periodId' => 2, 'entries' => 1, 'suggested' => 2],
            [
                'actorId' => 1,
                'loadId' => 2,
                'periodId' => 3,
                'schoolId' => 4,
                'sectionId' => 5,
                'subjectId' => 6,
                'values' => 2,
            ],
            ['actorId' => 1, 'schoolId' => 4, 'requestId' => 7],
        ], $logged);
    }
}
