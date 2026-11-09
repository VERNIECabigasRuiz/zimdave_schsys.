<?php

declare(strict_types=1);

namespace App\Tests\LessonPlans\Unit\Application;

use App\LessonPlans\Application\UseCase\Command\DeleteLessonPlanCommand;
use App\LessonPlans\Application\UseCase\Command\SaveLessonPlanCommand;
use App\LessonPlans\Application\UseCase\Command\StartLessonPlanCommand;
use PHPUnit\Framework\TestCase;

final class LessonPlanCommandLogTest extends TestCase
{
    public function testThePlansTextIsNotLogged(): void
    {
        // Initialize
        $commands = [
            new StartLessonPlanCommand(1, 7, new \DateTimeImmutable('2026-11-25')),
            new SaveLessonPlanCommand(1, 9, 'Fractions', 'M8NS-Ia-1', 'Slow readers', 'Textbook p. 4', 'None', [
                ['date' => '2026-11-23', 'holiday' => null, 'objectives' => 'Add fractions'],
            ]),
            new DeleteLessonPlanCommand(1, 9),
        ];

        // Execute
        $logged = array_map(static fn ($command) => $command->toLogArray(), $commands);

        // Assert
        self::assertSame([
            ['actorId' => 1, 'loadId' => 7, 'week' => '2026-11-25'],
            ['actorId' => 1, 'planId' => 9, 'days' => 1],
            ['actorId' => 1, 'planId' => 9],
        ], $logged);
    }
}
