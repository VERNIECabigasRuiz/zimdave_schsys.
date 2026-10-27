<?php

namespace App\Entity;

use App\Repository\SubjectRepository;
use App\Validator\UniqueSubject;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A subject a school offers to one grade level in a school year. A subject with a
 * parent is a component (Music and Arts of MAPEH); components are loaded and graded,
 * and the parent's grade is their average.
 */
#[ORM\Entity(repositoryClass: SubjectRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_subject_code', columns: ['school_id', 'school_year_id', 'code'])]
#[UniqueSubject]
class Subject
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

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: 'Enter the subject code.')]
    #[Assert\Length(max: 20)]
    #[Assert\Regex(pattern: '/^[A-Z0-9-]*$/', message: 'Use only letters, numbers and dashes in the code.')]
    private string $code = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Enter the subject name.')]
    #[Assert\Length(max: 100)]
    private string $name = '';

    #[ORM\ManyToOne(inversedBy: 'components')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Subject $parent = null;

    /** @var Collection<int, Subject> */
    #[ORM\OneToMany(targetEntity: Subject::class, mappedBy: 'parent', cascade: ['remove'])]
    #[ORM\OrderBy(['position' => 'ASC', 'name' => 'ASC'])]
    private Collection $components;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Assert\Choice(choices: [1, 2])]
    private ?int $semester = null;

    #[ORM\Column(length: 20, nullable: true, enumType: ShsTrack::class)]
    private ?ShsTrack $track = null;

    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60)]
    private ?string $strand = null;

    /** Report-card order within the grade (and semester). */
    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\Positive]
    private ?int $position = 1;

    /** How many class meetings a week; null on a subject with components (its components are loaded instead). */
    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Assert\Range(min: 1, max: 10)]
    private ?int $meetingsPerWeek = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(School $school, SchoolYear $schoolYear)
    {
        $this->school = $school;
        $this->schoolYear = $schoolYear;
        $this->components = new ArrayCollection();
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = mb_strtoupper(trim($code ?? ''));

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim($name ?? '');

        return $this;
    }

    public function getParent(): ?Subject
    {
        return $this->parent;
    }

    /** Keeps the parent's component list in step, so rules see it before a flush. */
    public function setParent(?Subject $parent): static
    {
        if ($parent === $this->parent) {
            return $this;
        }
        $this->parent?->components->removeElement($this);
        $this->parent = $parent;
        if (null !== $parent && !$parent->components->contains($this)) {
            $parent->components->add($this);
        }

        return $this;
    }

    /** @return Collection<int, Subject> */
    public function getComponents(): Collection
    {
        return $this->components;
    }

    public function isComponent(): bool
    {
        return null !== $this->parent;
    }

    public function hasComponents(): bool
    {
        return !$this->components->isEmpty();
    }

    public function getSemester(): ?int
    {
        return $this->semester;
    }

    public function setSemester(?int $semester): static
    {
        $this->semester = $semester;

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

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getMeetingsPerWeek(): ?int
    {
        return $this->meetingsPerWeek;
    }

    public function setMeetingsPerWeek(?int $meetingsPerWeek): static
    {
        $this->meetingsPerWeek = $meetingsPerWeek;

        return $this;
    }

    /** Only a subject without components gets a teacher and meetings in the timetable. */
    public function isLoadable(): bool
    {
        return !$this->hasComponents();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** "MATH7 – Mathematics"; a component: "MA7 – Music and Arts (MAPEH)". */
    public function getLabel(): string
    {
        $label = $this->code.' – '.$this->name;

        return null !== $this->parent ? $label.' ('.$this->parent->getName().')' : $label;
    }

    /** A component is taught in its parent's semester, track and strand. */
    public function inheritFromParent(): static
    {
        if (null !== $this->parent) {
            $this->semester = $this->parent->getSemester();
            $this->track = $this->parent->getTrack();
            $this->strand = $this->parent->getStrand();
        }

        return $this;
    }

    #[Assert\Callback]
    public function validateLevelAndParent(ExecutionContextInterface $context): void
    {
        // Only timetables need it, and a school without a principal (Individual plan) has none
        if (null === $this->meetingsPerWeek && $this->isLoadable() && false !== $this->school->getPlan()?->includesPrincipal()) {
            $context->buildViolation('Set how many times a week this subject meets.')->atPath('meetingsPerWeek')->addViolation();
        }
        if (null === $this->gradeLevel) {
            return;
        }
        $type = $this->school->getType();
        if (null !== $type && !\in_array($this->gradeLevel, $type->gradeLevels(), true)) {
            $context->buildViolation(\sprintf('%s is not offered by %s.', $this->gradeLevel->label(), $type->withArticle()))->atPath('gradeLevel')->addViolation();
        }
        if ($this->gradeLevel->isSeniorHigh() && null === $this->semester) {
            $context->buildViolation('Choose the semester of this Senior High subject.')->atPath('semester')->addViolation();
        }
        if (!$this->gradeLevel->isSeniorHigh() && (null !== $this->semester || null !== $this->track || null !== $this->strand)) {
            $context->buildViolation('Only Grade 11 and 12 subjects have a semester, track and strand.')->atPath('semester')->addViolation();
        }

        // Components keep their own grade, so a parent moved to another grade would leave them behind
        foreach ($this->components as $component) {
            if ($component->getGradeLevel() !== $this->gradeLevel) {
                $context->buildViolation(\sprintf('Move or delete the components of %s before changing its grade level.', $this->code))->atPath('gradeLevel')->addViolation();
                break;
            }
        }

        if (null === $parent = $this->parent) {
            return;
        }
        $message = match (true) {
            $parent === $this => 'A subject cannot be a component of itself.',
            $parent->getSchool() !== $this->school || $parent->getSchoolYear() !== $this->schoolYear => 'A component must be a subject of the same school and school year.',
            $parent->getGradeLevel() !== $this->gradeLevel => 'A component must be in the same grade level as its subject.',
            $parent->isComponent() => 'A component cannot have components of its own.',
            $this->hasComponents() => \sprintf('%s – %s has components, so it cannot be a component itself.', $this->code, $this->name),
            default => null,
        };
        if (null !== $message) {
            $context->buildViolation($message)->atPath('parent')->addViolation();
        }
    }
}
