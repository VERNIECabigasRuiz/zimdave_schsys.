<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Schools\Domain\Model\GradingSystem;
use Symfony\Component\Validator\Constraints as Assert;

/** The fields an admin fills in for a school year. */
abstract class SchoolYearDetails implements UseCase
{
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{4}-\d{4}$/', message: 'Use the format 2026-2027.')]
    public string $name = '';

    #[Assert\NotNull]
    public ?\DateTimeImmutable $startDate = null;

    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: 'startDate', message: 'The end date must be after the start date.')]
    public ?\DateTimeImmutable $endDate = null;

    #[Assert\NotNull]
    public GradingSystem $gradingSystem = GradingSystem::Terms;

    public function toLogArray(): array
    {
        return ['name' => $this->name, 'gradingSystem' => $this->gradingSystem->value];
    }
}
