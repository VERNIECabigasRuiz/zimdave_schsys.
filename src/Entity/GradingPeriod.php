<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A term or quarter of a school year. Teachers can only enter grades while it is open.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['school_year_id', 'number'])]
class GradingPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $open = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'gradingPeriods')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private SchoolYear $schoolYear,
        #[ORM\Column(type: 'smallint')]
        private int $number,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSchoolYear(): SchoolYear
    {
        return $this->schoolYear;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getLabel(): string
    {
        return $this->schoolYear->getGradingSystem()->periodName().' '.$this->number;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function setOpen(bool $open): static
    {
        $this->open = $open;

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
}
