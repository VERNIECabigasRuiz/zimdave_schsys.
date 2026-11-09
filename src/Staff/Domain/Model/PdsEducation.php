<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

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
    public ?EducationLevel $level = null;

    #[ORM\Column(length: 200)]
    public ?string $schoolName = null;

    /** Basic education / degree / course. */
    #[ORM\Column(length: 200, nullable: true)]
    public ?string $course = null;

    #[ORM\Column(nullable: true)]
    public ?int $yearFrom = null;

    #[ORM\Column(nullable: true)]
    public ?int $yearTo = null;

    /** Highest level / units earned, if not graduated. */
    #[ORM\Column(length: 100, nullable: true)]
    public ?string $highestLevel = null;

    #[ORM\Column(nullable: true)]
    public ?int $yearGraduated = null;

    /** Scholarship / academic honors received. */
    #[ORM\Column(length: 200, nullable: true)]
    public ?string $honors = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
