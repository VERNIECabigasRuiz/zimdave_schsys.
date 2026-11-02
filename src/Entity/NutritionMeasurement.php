<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One learner's weight and height in a weighing. Remarks may stand alone (absent on the day). */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_nutrition_measurement', columns: ['assessment_id', 'enrollment_id'])]
class NutritionMeasurement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'measurements')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NutritionAssessment $assessment;

    /** Unenrolling a learner deletes their measurements. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Enrollment $enrollment;

    /** Kilograms. */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $weightKg = null;

    /** Metres. */
    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 2, nullable: true)]
    private ?string $heightM = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $remarks = null;

    public function __construct(NutritionAssessment $assessment, Enrollment $enrollment)
    {
        $this->assessment = $assessment;
        $this->enrollment = $enrollment;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssessment(): NutritionAssessment
    {
        return $this->assessment;
    }

    public function getEnrollment(): Enrollment
    {
        return $this->enrollment;
    }

    public function getWeightKg(): ?string
    {
        return $this->weightKg;
    }

    public function setWeightKg(?string $weightKg): static
    {
        $this->weightKg = $weightKg;

        return $this;
    }

    public function getHeightM(): ?string
    {
        return $this->heightM;
    }

    public function setHeightM(?string $heightM): static
    {
        $this->heightM = $heightM;

        return $this;
    }

    public function getRemarks(): ?string
    {
        return $this->remarks;
    }

    public function setRemarks(?string $remarks): static
    {
        $this->remarks = $remarks;

        return $this;
    }

    /** Both numbers are in. */
    public function isWeighed(): bool
    {
        return null !== $this->weightKg && null !== $this->heightM;
    }
}
