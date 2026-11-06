<?php

declare(strict_types=1);

namespace App\Billing\Application\Transformer;

use App\Billing\Domain\Model\Plan;

/**
 * @phpstan-type PlanData array{id: int, name: string, teachersPerSeat: int, studentsPerSeat: int,
 *     includesPrincipal: bool}
 */
final class PlanTransformer
{
    /** @return PlanData */
    public static function toArray(Plan $plan): array
    {
        return [
            'id' => (int) $plan->getId(),
            'name' => $plan->getName(),
            'teachersPerSeat' => $plan->getTeachersPerSeat(),
            'studentsPerSeat' => $plan->getStudentsPerSeat(),
            'includesPrincipal' => $plan->includesPrincipal(),
        ];
    }
}
