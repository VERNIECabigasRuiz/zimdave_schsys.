<?php

namespace App\Entity;

use App\Repository\EnrollmentRepository;
use Doctrine\ORM\Mapping as ORM;

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

    public function __construct(Learner $learner, SchoolYear $schoolYear, Section $section, User $enrolledBy)
    {
        $this->learner = $learner;
        $this->schoolYear = $schoolYear;
        $this->school = $section->getSchool();
        $this->section = $section;
        $this->enrolledBy = $enrolledBy;
        $this->enrolledAt = new \DateTimeImmutable();
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

    /** Moves to another section, in this school or (after an approved transfer) another. */
    public function moveTo(Section $section): static
    {
        $this->section = $section;
        $this->school = $section->getSchool();

        return $this;
    }
}
