<?php

declare(strict_types=1);

namespace App\Schools\Application\Transformer;

use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\GradingSystem;
use App\Schools\Domain\Model\SchoolYear;

/**
 * @phpstan-type GradingPeriodData array{id: ?int, number: int, label: string,
 *     startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable}
 * @phpstan-type SchoolYearData array{id: ?int, name: string, startDate: ?\DateTimeImmutable,
 *     endDate: ?\DateTimeImmutable, current: bool, gradingSystem: GradingSystem,
 *     gradingPeriods: list<GradingPeriodData>}
 */
final class SchoolYearTransformer
{
    /** @return SchoolYearData */
    public static function toArray(SchoolYear $schoolYear): array
    {
        return [
            'id' => $schoolYear->getId(),
            'name' => $schoolYear->getName(),
            'startDate' => $schoolYear->getStartDate(),
            'endDate' => $schoolYear->getEndDate(),
            'current' => $schoolYear->isCurrent(),
            'gradingSystem' => $schoolYear->getGradingSystem(),
            'gradingPeriods' => array_values(array_map(
                static fn (GradingPeriod $period) => [
                    'id' => $period->getId(),
                    'number' => $period->getNumber(),
                    'label' => $period->getLabel(),
                    'startDate' => $period->getStartDate(),
                    'endDate' => $period->getEndDate(),
                ],
                $schoolYear->getGradingPeriods()->toArray(),
            )),
        ];
    }
}
