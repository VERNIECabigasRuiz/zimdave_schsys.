<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Command;

use App\Core\Application\Bus\Query;
use App\Staff\Application\UseCase\Command\SaveStaffIdDetailsCommand;

/**
 * A teacher's or principal's own ID page. After a refused save, $typed holds what they typed, and the card
 * shows it instead of their saved details.
 */
final readonly class GetMyIdCommand implements Query
{
    public function __construct(
        public int $actorId,
        public ?SaveStaffIdDetailsCommand $typed = null,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'typed' => null !== $this->typed];
    }
}
