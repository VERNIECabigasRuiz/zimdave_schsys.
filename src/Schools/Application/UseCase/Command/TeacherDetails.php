<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/** The fields a principal or admin fills in for a teacher's account. */
abstract class TeacherDetails implements UseCase
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $fullName = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 50)]
    #[Assert\Regex(pattern: '/^[a-zA-Z0-9._-]+$/', message: 'Use only letters, numbers, dots, dashes and underscores.')]
    public string $username = '';

    public function __construct(public readonly int $schoolId)
    {
    }

    /** The account the username may already belong to (none for a new teacher). */
    public function accountId(): ?int
    {
        return null;
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'username' => $this->username];
    }
}
