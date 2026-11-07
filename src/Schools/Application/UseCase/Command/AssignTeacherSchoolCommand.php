<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/** An admin makes a teacher account teach at a school this school year. Bound to the teacher school form. */
final class AssignTeacherSchoolCommand implements UseCase
{
    #[Assert\NotNull(message: 'Choose a school.')]
    public ?int $schoolId = null;

    public function __construct(public readonly int $accountId)
    {
    }

    public function toLogArray(): array
    {
        return ['accountId' => $this->accountId, 'schoolId' => $this->schoolId];
    }
}
