<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The person opens their profile: their Personal Data Sheet is started on the first visit. */
final readonly class OpenPersonalDataSheetCommand implements UseCase
{
    public function __construct(public int $actorId)
    {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId];
    }
}
