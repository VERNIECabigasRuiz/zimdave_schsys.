<?php

declare(strict_types=1);

namespace App\Classes\Application\Transformer;

use App\Classes\Domain\Model\ClassPeriod;

/**
 * @phpstan-type ClassPeriodData array{id: int, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable,
 *     break: bool, breakLabel: ?string, timeRange: string}
 */
final class ClassPeriodTransformer
{
    /** @return ClassPeriodData */
    public static function toArray(ClassPeriod $period): array
    {
        return [
            'id' => (int) $period->getId(),
            'startsAt' => $period->getStartsAt(),
            'endsAt' => $period->getEndsAt(),
            'break' => $period->isBreak(),
            'breakLabel' => $period->getBreakLabel(),
            'timeRange' => $period->getTimeRange(),
        ];
    }
}
