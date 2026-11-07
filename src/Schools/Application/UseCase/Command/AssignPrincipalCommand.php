<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/** An admin makes a principal the head of a school for a school year. Bound to the principal form. */
final class AssignPrincipalCommand implements UseCase
{
    #[Assert\NotNull(message: 'Select a principal.')]
    public ?int $principalId = null;

    public function __construct(
        public readonly int $schoolId,
        public readonly ?int $schoolYearId = null,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'schoolId' => $this->schoolId,
            'schoolYearId' => $this->schoolYearId,
            'principalId' => $this->principalId,
        ];
    }
}
