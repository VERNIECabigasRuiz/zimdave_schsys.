<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Staff\Domain\Model\PdsWorkExperience;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Validator\Constraints as Assert;

/** One work experience. */
final class PdsWorkExperienceRow
{
    #[Assert\NotNull(message: 'Enter the start date.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?\DateTimeImmutable $dateFrom = null;

    #[Assert\GreaterThanOrEqual(
        propertyPath: 'dateFrom',
        message: 'The end date cannot be before the start date.',
        groups: PersonalDataSheet::LIST_GROUPS,
    )]
    public ?\DateTimeImmutable $dateTo = null;

    /** Still in this job: "To" prints as PRESENT. */
    public bool $present = false;

    #[Assert\NotBlank(message: 'Enter the position title.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\Length(max: 200, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $positionTitle = null;

    /** Department / agency / office / company. */
    #[Assert\NotBlank(
        message: 'Enter the department, agency, office or company.',
        groups: PersonalDataSheet::LIST_GROUPS,
    )]
    #[Assert\Length(max: 255, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $office = null;

    #[Assert\Regex(
        pattern: '/^\d{1,10}(\.\d{1,2})?$/',
        message: 'Enter the salary as a number, e.g. 30024.50.',
        groups: PersonalDataSheet::LIST_GROUPS,
    )]
    public ?string $monthlySalary = null;

    /** Salary/job/pay grade and step, "00-0". */
    #[Assert\Regex(
        pattern: '/^\d{2}-\d$/',
        message: 'Use the format 00-0, e.g. 11-1.',
        groups: PersonalDataSheet::LIST_GROUPS,
    )]
    public ?string $salaryGrade = null;

    #[Assert\Length(max: 50, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $appointmentStatus = null;

    public bool $governmentService = true;

    public static function fromEntity(PdsWorkExperience $entity): self
    {
        $row = new self();
        $row->dateFrom = $entity->dateFrom;
        $row->dateTo = $entity->dateTo;
        $row->present = $entity->present;
        $row->positionTitle = $entity->positionTitle;
        $row->office = $entity->office;
        $row->monthlySalary = $entity->monthlySalary;
        $row->salaryGrade = $entity->salaryGrade;
        $row->appointmentStatus = $entity->appointmentStatus;
        $row->governmentService = $entity->governmentService;

        return $row;
    }

    public function applyTo(PdsWorkExperience $entity): void
    {
        $entity->dateFrom = $this->dateFrom;
        $entity->dateTo = $this->dateTo;
        $entity->present = $this->present;
        $entity->positionTitle = $this->positionTitle;
        $entity->office = $this->office;
        $entity->monthlySalary = $this->monthlySalary;
        $entity->salaryGrade = $this->salaryGrade;
        $entity->appointmentStatus = $this->appointmentStatus;
        $entity->governmentService = $this->governmentService;
    }
}
