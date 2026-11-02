<?php

namespace App\Entity;

use App\Repository\ClassPeriodRepository;
use App\Validator\NoOverlappingPeriod;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A slot of the school day in one school year (07:30–08:30). A slot with a break
 * label (Recess, Lunch) is not a class period and is never numbered or plotted.
 */
#[ORM\Entity(repositoryClass: ClassPeriodRepository::class)]
#[NoOverlappingPeriod]
class ClassPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private School $school;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SchoolYear $schoolYear;

    /** Order of the day, kept in step with the start time by ClassPeriodManager. */
    #[ORM\Column]
    private int $position = 1;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull(message: 'Enter the start time.')]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull(message: 'Enter the end time.')]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $breakLabel = null;

    public function __construct(School $school, SchoolYear $schoolYear)
    {
        $this->school = $school;
        $this->schoolYear = $schoolYear;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSchool(): School
    {
        return $this->school;
    }

    public function getSchoolYear(): SchoolYear
    {
        return $this->schoolYear;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = self::timeOnly($startsAt);

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = self::timeOnly($endsAt);

        return $this;
    }

    public function getBreakLabel(): ?string
    {
        return $this->breakLabel;
    }

    public function setBreakLabel(?string $breakLabel): static
    {
        $breakLabel = null === $breakLabel ? null : trim($breakLabel);
        $this->breakLabel = '' === $breakLabel ? null : $breakLabel;

        return $this;
    }

    public function isBreak(): bool
    {
        return null !== $this->breakLabel;
    }

    /** "07:30–08:30" */
    public function getTimeRange(): string
    {
        return ($this->startsAt?->format('H:i') ?? '').'–'.($this->endsAt?->format('H:i') ?? '');
    }

    #[Assert\Callback]
    public function validateTimes(ExecutionContextInterface $context): void
    {
        if (null !== $this->startsAt && null !== $this->endsAt && $this->endsAt->format('H:i') <= $this->startsAt->format('H:i')) {
            $context->buildViolation('The end time must be after the start time.')->atPath('endsAt')->addViolation();
        }
    }

    /** Keeps only the clock time, so values compare the same however they were built. */
    private static function timeOnly(?\DateTimeImmutable $time): ?\DateTimeImmutable
    {
        return $time ? new \DateTimeImmutable('1970-01-01 '.$time->format('H:i:s')) : null;
    }
}
