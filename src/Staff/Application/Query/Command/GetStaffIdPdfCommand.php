<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Command;

use App\Core\Application\Bus\Query;
use App\Staff\Domain\Service\StaffId\StaffIdSize;

/** A teacher's or principal's own ID as a PDF on the Big or Small template. */
final readonly class GetStaffIdPdfCommand implements Query
{
    public function __construct(
        public int $actorId,
        public StaffIdSize $size,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'size' => $this->size->value];
    }
}
