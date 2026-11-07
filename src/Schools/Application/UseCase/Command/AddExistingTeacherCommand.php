<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/** A principal adds a teacher who already has an account, by username. Bound to the existing teacher form. */
final class AddExistingTeacherCommand implements UseCase
{
    #[Assert\NotBlank(message: 'Enter the teacher\'s username.')]
    public string $username = '';

    public function __construct(public readonly int $schoolId)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'username' => $this->username];
    }
}
