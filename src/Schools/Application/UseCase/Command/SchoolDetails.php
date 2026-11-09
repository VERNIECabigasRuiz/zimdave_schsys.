<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Schools\Domain\Model\SchoolType;
use Symfony\Component\Validator\Constraints as Assert;

/** The fields an admin fills in for a school. */
abstract class SchoolDetails implements UseCase
{
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{6}$/', message: 'School ID must be exactly 6 digits.')]
    public string $schoolId = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    public string $name = '';

    #[Assert\NotNull]
    public ?SchoolType $type = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $region = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $division = null;

    #[Assert\Length(max: 100)]
    public ?string $district = null;

    #[Assert\Length(max: 255)]
    public ?string $address = null;

    #[Assert\NotNull(message: 'Choose a plan.')]
    public ?int $planId = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $seats = 1;

    public ?\DateTimeImmutable $planValidUntil = null;

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'name' => $this->name, 'planId' => $this->planId];
    }
}
