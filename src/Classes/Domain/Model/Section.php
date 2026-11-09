<?php

declare(strict_types=1);

namespace App\Classes\Domain\Model;

use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Access\Domain\Model\User;
use Doctrine\ORM\Mapping as ORM;

/** A class section of a school in one school year, with an optional class adviser. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_section_name', columns: ['school_id', 'school_year_id', 'grade_level', 'name'])]
#[ORM\UniqueConstraint(name: 'uniq_section_adviser', columns: ['school_year_id', 'adviser_id'])]
class Section
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

    #[ORM\Column(enumType: GradeLevel::class)]
    private ?GradeLevel $gradeLevel = null;

    #[ORM\Column(length: 60)]
    private string $name = '';

    #[ORM\Column(length: 20, nullable: true, enumType: ShsTrack::class)]
    private ?ShsTrack $track = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $strand = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $adviser = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(School $school, SchoolYear $schoolYear)
    {
        $this->school = $school;
        $this->schoolYear = $schoolYear;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getGradeLevel(): ?GradeLevel
    {
        return $this->gradeLevel;
    }

    public function setGradeLevel(?GradeLevel $gradeLevel): static
    {
        $this->gradeLevel = $gradeLevel;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getTrack(): ?ShsTrack
    {
        return $this->track;
    }

    public function setTrack(?ShsTrack $track): static
    {
        $this->track = $track;

        return $this;
    }

    public function getStrand(): ?string
    {
        return $this->strand;
    }

    public function setStrand(?string $strand): static
    {
        $this->strand = self::normalizeStrand($strand);

        return $this;
    }

    /** The strand as it is stored: trimmed, with an empty one as null. */
    public static function normalizeStrand(?string $strand): ?string
    {
        $strand = null !== $strand ? trim($strand) : null;

        return '' === $strand ? null : $strand;
    }

    public function getAdviser(): ?User
    {
        return $this->adviser;
    }

    public function setAdviser(?User $adviser): static
    {
        $this->adviser = $adviser;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** "Grade 7 – Sampaguita", "Grade 11 – STEM 1 (Academic – STEM)". */
    public function getLabel(): string
    {
        $label = ($this->gradeLevel?->label() ?? '?') . ' – ' . $this->name;
        if (null !== $this->track) {
            $label .= ' (' . $this->track->label() . (null !== $this->strand ? ' – ' . $this->strand : '') . ')';
        }

        return $label;
    }

    /** "Grade 8 – ARTISTIC", as the school forms print it; just "ARTISTIC" without a grade level. */
    public function getFormLabel(): string
    {
        return ltrim(($this->gradeLevel?->label() ?? '') . ' – ' . mb_strtoupper($this->name), ' –');
    }
}
