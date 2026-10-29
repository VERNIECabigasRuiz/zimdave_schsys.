<?php

namespace App\Entity;

use App\Repository\SchoolDayOffRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** A weekday of the school calendar without classes (holiday, suspension), kept by the principal. */
#[ORM\Entity(repositoryClass: SchoolDayOffRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_school_day_off', columns: ['school_id', 'date'])]
class SchoolDayOff
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

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $reason;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(School $school, SchoolYear $schoolYear, \DateTimeImmutable $date, string $reason, User $createdBy, \DateTimeImmutable $createdAt)
    {
        $this->school = $school;
        $this->schoolYear = $schoolYear;
        $this->date = $date->setTime(0, 0);
        $this->reason = trim($reason);
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
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

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
