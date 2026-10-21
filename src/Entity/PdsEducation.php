<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** PDS item 26: one school attended. */
#[ORM\Entity]
class PdsEducation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'educations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?PersonalDataSheet $sheet = null;

    #[ORM\Column]
    public int $position = 0;

    #[ORM\Column(length: 20, enumType: EducationLevel::class)]
    #[Assert\NotNull(message: 'Choose the level.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?EducationLevel $level = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Enter the name of the school.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\Length(max: 200, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $schoolName = null;

    /** Basic education / degree / course. */
    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $course = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1900, max: 2100, notInRangeMessage: 'Enter a year like 2010.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?int $yearFrom = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1900, max: 2100, notInRangeMessage: 'Enter a year like 2014.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'yearFrom', message: 'The end year cannot be before the start year.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?int $yearTo = null;

    /** Highest level / units earned, if not graduated. */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $highestLevel = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1900, max: 2100, notInRangeMessage: 'Enter a year like 2014.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?int $yearGraduated = null;

    /** Scholarship / academic honors received. */
    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $honors = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
