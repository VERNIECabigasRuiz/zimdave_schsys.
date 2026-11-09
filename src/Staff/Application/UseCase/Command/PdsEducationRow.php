<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Staff\Domain\Model\EducationLevel;
use App\Staff\Domain\Model\PdsEducation;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Validator\Constraints as Assert;

/** One school on the Educational Background tab. */
final class PdsEducationRow
{
    #[Assert\NotNull(message: 'Choose the level.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?EducationLevel $level = null;

    #[Assert\NotBlank(message: 'Enter the name of the school.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\Length(max: 200, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $schoolName = null;

    /** Basic education / degree / course. */
    #[Assert\Length(max: 200, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $course = null;

    #[Assert\Range(
        min: 1900,
        max: 2100,
        notInRangeMessage: 'Enter a year like 2010.',
        groups: PersonalDataSheet::LIST_GROUPS,
    )]
    public ?int $yearFrom = null;

    #[Assert\Range(
        min: 1900,
        max: 2100,
        notInRangeMessage: 'Enter a year like 2014.',
        groups: PersonalDataSheet::LIST_GROUPS,
    )]
    #[Assert\GreaterThanOrEqual(
        propertyPath: 'yearFrom',
        message: 'The end year cannot be before the start year.',
        groups: PersonalDataSheet::LIST_GROUPS,
    )]
    public ?int $yearTo = null;

    /** Highest level / units earned, if not graduated. */
    #[Assert\Length(max: 100, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $highestLevel = null;

    #[Assert\Range(
        min: 1900,
        max: 2100,
        notInRangeMessage: 'Enter a year like 2014.',
        groups: PersonalDataSheet::LIST_GROUPS,
    )]
    public ?int $yearGraduated = null;

    /** Scholarship / academic honors received. */
    #[Assert\Length(max: 200, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $honors = null;

    public static function fromEntity(PdsEducation $entity): self
    {
        $row = new self();
        $row->level = $entity->level;
        $row->schoolName = $entity->schoolName;
        $row->course = $entity->course;
        $row->yearFrom = $entity->yearFrom;
        $row->yearTo = $entity->yearTo;
        $row->highestLevel = $entity->highestLevel;
        $row->yearGraduated = $entity->yearGraduated;
        $row->honors = $entity->honors;

        return $row;
    }

    public function applyTo(PdsEducation $entity): void
    {
        $entity->level = $this->level;
        $entity->schoolName = $this->schoolName;
        $entity->course = $this->course;
        $entity->yearFrom = $this->yearFrom;
        $entity->yearTo = $this->yearTo;
        $entity->highestLevel = $this->highestLevel;
        $entity->yearGraduated = $this->yearGraduated;
        $entity->honors = $this->honors;
    }
}
