<?php

declare(strict_types=1);

namespace App\Tests\Nutrition\Unit\Application;

use App\Nutrition\Application\UseCase\Command\MeasurementInput;
use App\Nutrition\Application\UseCase\Command\ReopenSf8Command;
use App\Nutrition\Application\UseCase\Command\SaveWeighingCommand;
use App\Nutrition\Application\UseCase\Command\SubmitSf8Command;
use App\Nutrition\Domain\Model\NutritionPeriod;
use PHPUnit\Framework\TestCase;

final class NutritionCommandLogTest extends TestCase
{
    public function testRemarksAreNotLogged(): void
    {
        // Initialize
        $commands = [
            new SaveWeighingCommand(1, 5, NutritionPeriod::Baseline, new \DateTimeImmutable('2025-08-04'), [
                new MeasurementInput(10, 31.5, 1.32, 'Recovering from dengue'),
                new MeasurementInput(11, null, null, 'Absent'),
            ]),
            new SubmitSf8Command(1, 5, NutritionPeriod::Endline, true),
            new ReopenSf8Command(2, 4, 5, NutritionPeriod::Baseline),
        ];

        // Execute
        $logged = array_map(static fn ($command) => $command->toLogArray(), $commands);

        // Assert
        self::assertSame([
            ['actorId' => 1, 'sectionId' => 5, 'period' => 'baseline', 'weighedOn' => '2025-08-04', 'inputs' => 2],
            ['actorId' => 1, 'sectionId' => 5, 'period' => 'endline', 'confirmUnweighed' => true],
            ['actorId' => 2, 'schoolId' => 4, 'sectionId' => 5, 'period' => 'baseline'],
        ], $logged);
    }
}
