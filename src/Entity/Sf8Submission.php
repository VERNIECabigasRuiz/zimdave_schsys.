<?php

namespace App\Entity;

use App\Nutrition\NutritionPeriod;
use App\Repository\Sf8SubmissionRepository;
use Doctrine\ORM\Mapping as ORM;

/** An adviser's SF8 for one weighing period, handed to the principal. While it exists the period is locked. */
#[ORM\Entity(repositoryClass: Sf8SubmissionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_sf8_submission', columns: ['section_id', 'period'])]
class Sf8Submission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Section $section;

    #[ORM\Column(length: 10, enumType: NutritionPeriod::class)]
    private NutritionPeriod $period;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $submittedBy;

    #[ORM\Column]
    private \DateTimeImmutable $submittedAt;

    public function __construct(Section $section, NutritionPeriod $period, User $submittedBy, \DateTimeImmutable $submittedAt)
    {
        $this->section = $section;
        $this->period = $period;
        $this->submittedBy = $submittedBy;
        $this->submittedAt = $submittedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function getPeriod(): NutritionPeriod
    {
        return $this->period;
    }

    public function getSubmittedBy(): User
    {
        return $this->submittedBy;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }
}
