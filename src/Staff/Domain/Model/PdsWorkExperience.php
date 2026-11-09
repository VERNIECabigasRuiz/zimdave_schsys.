<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

/** PDS item 28: one job, government or private. */
#[ORM\Entity]
class PdsWorkExperience
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'workExperiences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?PersonalDataSheet $sheet = null;

    #[ORM\Column]
    public int $position = 0;

    #[ORM\Column(type: 'date_immutable')]
    public ?\DateTimeImmutable $dateFrom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $dateTo = null;

    /** Still in this job: "To" prints as PRESENT. */
    #[ORM\Column]
    public bool $present = false;

    #[ORM\Column(length: 200)]
    public ?string $positionTitle = null;

    /** Department / agency / office / company. */
    #[ORM\Column(length: 255)]
    public ?string $office = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    public ?string $monthlySalary = null;

    /** Salary/job/pay grade and step, "00-0". */
    #[ORM\Column(length: 10, nullable: true)]
    public ?string $salaryGrade = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $appointmentStatus = null;

    #[ORM\Column]
    public bool $governmentService = true;

    public function getId(): ?int
    {
        return $this->id;
    }
}
