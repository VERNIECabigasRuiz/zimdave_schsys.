<?php

namespace App\Entity;

use App\Repository\EnrollmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** A learner's place in one section for one school year. */
#[ORM\Entity(repositoryClass: EnrollmentRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_enrollment_learner_year', columns: ['learner_id', 'school_year_id'])]
class Enrollment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Learner $learner;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SchoolYear $schoolYear;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private School $school;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Section $section;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $enrolledBy;

    #[ORM\Column]
    private \DateTimeImmutable $enrolledAt;

    #[ORM\Column(length: 20, enumType: LearningModality::class, options: ['default' => 'face_to_face'])]
    private LearningModality $learningModality = LearningModality::FaceToFace;

    #[ORM\Column(length: 20, nullable: true, enumType: Sf1Remark::class)]
    private ?Sf1Remark $remarkCode = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $remarkDetails = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $remarkDate = null;

    public function __construct(Learner $learner, SchoolYear $schoolYear, Section $section, User $enrolledBy, \DateTimeImmutable $enrolledAt)
    {
        $this->learner = $learner;
        $this->schoolYear = $schoolYear;
        $this->school = $section->getSchool();
        $this->section = $section;
        $this->enrolledBy = $enrolledBy;
        $this->enrolledAt = $enrolledAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLearner(): Learner
    {
        return $this->learner;
    }

    public function getSchoolYear(): SchoolYear
    {
        return $this->schoolYear;
    }

    public function getSchool(): School
    {
        return $this->school;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function getEnrolledBy(): User
    {
        return $this->enrolledBy;
    }

    public function getEnrolledAt(): \DateTimeImmutable
    {
        return $this->enrolledAt;
    }

    public function getLearningModality(): LearningModality
    {
        return $this->learningModality;
    }

    public function setLearningModality(LearningModality $learningModality): static
    {
        $this->learningModality = $learningModality;

        return $this;
    }

    public function getRemarkCode(): ?Sf1Remark
    {
        return $this->remarkCode;
    }

    public function setRemarkCode(?Sf1Remark $remarkCode): static
    {
        $this->remarkCode = $remarkCode;

        return $this;
    }

    public function getRemarkDetails(): ?string
    {
        return $this->remarkDetails;
    }

    public function setRemarkDetails(?string $remarkDetails): static
    {
        $remarkDetails = null !== $remarkDetails ? trim($remarkDetails) : null;
        $this->remarkDetails = '' === $remarkDetails ? null : $remarkDetails;

        return $this;
    }

    public function getRemarkDate(): ?\DateTimeImmutable
    {
        return $this->remarkDate;
    }

    public function setRemarkDate(?\DateTimeImmutable $remarkDate): static
    {
        $this->remarkDate = $remarkDate;

        return $this;
    }

    /** "DRP Moved abroad 2025-09-01", or null without a remark code */
    public function getManualRemarkText(): ?string
    {
        if (null === $this->remarkCode) {
            return null;
        }

        return implode(' ', array_filter([$this->remarkCode->code(), $this->remarkDetails, $this->remarkDate?->format('Y-m-d')]));
    }

    /** Moves to another section, in this school or (after an approved transfer) another. */
    public function moveTo(Section $section): static
    {
        $this->section = $section;
        $this->school = $section->getSchool();

        return $this;
    }
}
