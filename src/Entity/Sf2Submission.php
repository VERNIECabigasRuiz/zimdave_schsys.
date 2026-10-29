<?php

namespace App\Entity;

use App\Repository\Sf2SubmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** An adviser's SF2 for one month, handed to the principal. While it exists the month is locked. */
#[ORM\Entity(repositoryClass: Sf2SubmissionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_sf2_submission', columns: ['section_id', 'month'])]
class Sf2Submission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Section $section;

    /** Always the 1st of the month. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $month;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $submittedBy;

    #[ORM\Column]
    private \DateTimeImmutable $submittedAt;

    public function __construct(Section $section, \DateTimeImmutable $month, User $submittedBy, \DateTimeImmutable $submittedAt)
    {
        $this->section = $section;
        $this->month = $month->modify('first day of this month')->setTime(0, 0);
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

    public function getMonth(): \DateTimeImmutable
    {
        return $this->month;
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
