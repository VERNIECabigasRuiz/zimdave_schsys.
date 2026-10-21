<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

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

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Enter the start date.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?\DateTimeImmutable $dateFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'dateFrom', message: 'The end date cannot be before the start date.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?\DateTimeImmutable $dateTo = null;

    /** Still in this job: "To" prints as PRESENT. */
    #[ORM\Column]
    public bool $present = false;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Enter the position title.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\Length(max: 200, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $positionTitle = null;

    /** Department / agency / office / company. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Enter the department, agency, office or company.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\Length(max: 255, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $office = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{1,10}(\.\d{1,2})?$/', message: 'Enter the salary as a number, e.g. 30024.50.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $monthlySalary = null;

    /** Salary/job/pay grade and step, "00-0". */
    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{2}-\d$/', message: 'Use the format 00-0, e.g. 11-1.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $salaryGrade = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $appointmentStatus = null;

    #[ORM\Column]
    public bool $governmentService = true;

    public function getId(): ?int
    {
        return $this->id;
    }
}
