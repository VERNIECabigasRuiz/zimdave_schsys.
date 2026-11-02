<?php

namespace App\Entity;

use App\Nutrition\NutritionPeriod;
use App\Repository\NutritionAssessmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A section's baseline or endline weighing: the date it happened and each learner's measurements. */
#[ORM\Entity(repositoryClass: NutritionAssessmentRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_nutrition_assessment', columns: ['section_id', 'period'])]
class NutritionAssessment
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

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $weighedOn;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, NutritionMeasurement> */
    #[ORM\OneToMany(targetEntity: NutritionMeasurement::class, mappedBy: 'assessment', cascade: ['persist'], orphanRemoval: true)]
    private Collection $measurements;

    public function __construct(Section $section, NutritionPeriod $period, \DateTimeImmutable $weighedOn, User $createdBy, \DateTimeImmutable $now)
    {
        $this->section = $section;
        $this->period = $period;
        $this->weighedOn = $weighedOn->setTime(0, 0);
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->measurements = new ArrayCollection();
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

    public function getWeighedOn(): \DateTimeImmutable
    {
        return $this->weighedOn;
    }

    public function setWeighedOn(\DateTimeImmutable $weighedOn): static
    {
        $this->weighedOn = $weighedOn->setTime(0, 0);

        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(\DateTimeImmutable $at): static
    {
        $this->updatedAt = $at;

        return $this;
    }

    /** @return Collection<int, NutritionMeasurement> */
    public function getMeasurements(): Collection
    {
        return $this->measurements;
    }

    public function measurementFor(Enrollment $enrollment): ?NutritionMeasurement
    {
        foreach ($this->measurements as $measurement) {
            $other = $measurement->getEnrollment();
            if ($other === $enrollment || (null !== $enrollment->getId() && $other->getId() === $enrollment->getId())) {
                return $measurement;
            }
        }

        return null;
    }

    public function addMeasurement(NutritionMeasurement $measurement): static
    {
        if (!$this->measurements->contains($measurement)) {
            $this->measurements->add($measurement);
        }

        return $this;
    }

    public function removeMeasurement(NutritionMeasurement $measurement): static
    {
        $this->measurements->removeElement($measurement);

        return $this;
    }
}
