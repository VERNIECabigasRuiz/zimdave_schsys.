<?php

declare(strict_types=1);

namespace App\Billing\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** An admin deletes a plan no school uses. */
final readonly class DeletePlanCommand implements UseCase
{
    public function __construct(public int $id)
    {
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id];
    }
}
