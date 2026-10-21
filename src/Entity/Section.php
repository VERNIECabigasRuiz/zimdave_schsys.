<?php

namespace App\Entity;

use App\Repository\SectionRepository;
use App\Validator\UniqueSection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** A class section of a school in one school year, with an optional class adviser. */
#[ORM\Entity(repositoryClass: SectionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_section_name', columns: ['school_id', 'school_year_id', 'grade_level', 'name'])]
#[ORM\UniqueConstraint(name: 'uniq_section_adviser', columns: ['school_year_id', 'adviser_id'])]
#[UniqueSection]
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
    #[Assert\NotNull(message: 'Choose the grade level.')]
    private ?GradeLevel $gradeLevel = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'Enter the section name.')]
    #[Assert\Length(max: 60)]
    private string $name = '';

    #[ORM\Column(length: 20, nullable: true, enumType: ShsTrack::class)]
    private ?ShsTrack $track = null;

    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60)]
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
        $strand = null !== $strand ? trim($strand) : null;
        $this->strand = '' === $strand ? null : $strand;

        return $this;
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
        $label = ($this->gradeLevel?->label() ?? '?').' – '.$this->name;
        if (null !== $this->track) {
            $label .= ' ('.$this->track->label().(null !== $this->strand ? ' – '.$this->strand : '').')';
        }

        return $label;
    }

    #[Assert\Callback]
    public function validateLevelAndTrack(ExecutionContextInterface $context): void
    {
        if (null === $this->gradeLevel) {
            return;
        }
        $type = $this->school->getType();
        if (null !== $type && !\in_array($this->gradeLevel, $type->gradeLevels(), true)) {
            $context->buildViolation(\sprintf('%s is not offered by %s.', $this->gradeLevel->label(), $type->withArticle()))->atPath('gradeLevel')->addViolation();
        }
        if ($this->gradeLevel->isSeniorHigh() && null === $this->track) {
            $context->buildViolation('Choose the track of this Senior High section.')->atPath('track')->addViolation();
        }
        if (!$this->gradeLevel->isSeniorHigh() && (null !== $this->track || null !== $this->strand)) {
            $context->buildViolation('Only Grade 11 and 12 sections have a track and strand.')->atPath('track')->addViolation();
        }
    }
}
