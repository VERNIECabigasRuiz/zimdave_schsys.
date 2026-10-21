<?php

namespace App\Entity;

use App\Repository\SchoolYearRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: SchoolYearRepository::class)]
#[UniqueEntity(fields: ['name'], message: 'This school year already exists.')]
class SchoolYear
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{4}-\d{4}$/', message: 'Use the format 2026-2027.')]
    private string $name = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: 'startDate', message: 'The end date must be after the start date.')]
    private ?\DateTimeImmutable $endDate = null;

    /** Only one school year is current at a time. */
    #[ORM\Column]
    private bool $current = false;

    #[ORM\Column(length: 10, enumType: GradingSystem::class)]
    #[Assert\NotNull]
    private GradingSystem $gradingSystem = GradingSystem::Terms;

    /** @var Collection<int, GradingPeriod> */
    #[ORM\OneToMany(targetEntity: GradingPeriod::class, mappedBy: 'schoolYear', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    #[Assert\Valid]
    private Collection $gradingPeriods;

    public function __construct()
    {
        $this->gradingPeriods = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function isCurrent(): bool
    {
        return $this->current;
    }

    public function setCurrent(bool $current): static
    {
        $this->current = $current;

        return $this;
    }

    /**
     * @return Collection<int, GradingPeriod>
     */
    public function getGradingPeriods(): Collection
    {
        return $this->gradingPeriods;
    }

    public function getGradingPeriod(int $number): ?GradingPeriod
    {
        foreach ($this->gradingPeriods as $period) {
            if ($period->getNumber() === $number) {
                return $period;
            }
        }

        return null;
    }

    public function getGradingSystem(): GradingSystem
    {
        return $this->gradingSystem;
    }

    public function setGradingSystem(GradingSystem $gradingSystem): static
    {
        $this->gradingSystem = $gradingSystem;

        return $this;
    }

    public function addGradingPeriod(GradingPeriod $period): static
    {
        if (!$this->gradingPeriods->contains($period)) {
            $this->gradingPeriods->add($period);
        }

        return $this;
    }

    public function removeGradingPeriod(GradingPeriod $period): static
    {
        $this->gradingPeriods->removeElement($period);

        return $this;
    }

    /**
     * False while the periods still belong to the previous grading system (e.g. 4 quarters after picking terms).
     */
    public function hasMatchingGradingPeriods(): bool
    {
        return $this->gradingPeriods->count() === $this->gradingSystem->periodCount();
    }

    public function hasOpenGradingPeriod(): bool
    {
        return $this->gradingPeriods->exists(static fn ($key, GradingPeriod $period) => $period->isOpen());
    }

    /**
     * Makes the grading periods match the grading system, closed and splitting the school year evenly.
     * Existing periods are reused by number so the (school year, number) unique key never clashes on flush.
     */
    public function rebuildGradingPeriods(): void
    {
        if (null === $this->startDate || null === $this->endDate) {
            throw new \LogicException('Set the school year dates before building its grading periods.');
        }

        $count = $this->gradingSystem->periodCount();
        $totalDays = (int) $this->startDate->diff($this->endDate)->days + 1;

        foreach ($this->gradingPeriods->toArray() as $period) {
            if ($period->getNumber() > $count) {
                $this->gradingPeriods->removeElement($period);
            }
        }

        for ($number = 1; $number <= $count; ++$number) {
            $period = $this->getGradingPeriod($number);
            if (null === $period) {
                $period = new GradingPeriod($this, $number);
                $this->gradingPeriods->add($period);
            }

            $firstDay = intdiv($totalDays * ($number - 1), $count);
            $nextFirstDay = intdiv($totalDays * $number, $count);
            $period
                ->setOpen(false)
                ->setStartDate($this->startDate->modify(\sprintf('+%d days', $firstDay)))
                ->setEndDate($this->startDate->modify(\sprintf('+%d days', $nextFirstDay - 1)));
        }
    }

    #[Assert\Callback]
    public function validateGradingPeriods(ExecutionContextInterface $context): void
    {
        // Leftover periods of the previous system are rebuilt on save, so their dates don't matter
        if (!$this->hasMatchingGradingPeriods()) {
            return;
        }

        $previous = null;
        foreach ($this->gradingPeriods->getValues() as $index => $period) {
            $start = $period->getStartDate();
            $end = $period->getEndDate();
            $path = \sprintf('gradingPeriods[%d].', $index);

            if (null === $start || null === $end) {
                $context->buildViolation(\sprintf('Enter the dates of %s.', $period->getLabel()))->atPath($path.'startDate')->addViolation();
            } elseif ($end <= $start) {
                $context->buildViolation(\sprintf('%s must end after it starts.', $period->getLabel()))->atPath($path.'endDate')->addViolation();
            } elseif (null !== $this->startDate && null !== $this->endDate && ($start < $this->startDate || $end > $this->endDate)) {
                $context->buildViolation(\sprintf('%s must be within the school year.', $period->getLabel()))->atPath($path.'startDate')->addViolation();
            } elseif (null !== $previous?->getEndDate() && $start <= $previous->getEndDate()) {
                $context->buildViolation(\sprintf('%s must start after %s ends.', $period->getLabel(), $previous->getLabel()))->atPath($path.'startDate')->addViolation();
            }

            $previous = $period;
        }
    }
}
