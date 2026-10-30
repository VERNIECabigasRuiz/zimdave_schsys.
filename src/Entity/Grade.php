<?php

namespace App\Entity;

use App\Repository\GradeRepository;
use Doctrine\ORM\Mapping as ORM;

/** A learner's whole-number grade (60-100) in one subject for one grading period. A blank grade has no row. */
#[ORM\Entity(repositoryClass: GradeRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_grade', columns: ['enrollment_id', 'subject_id', 'grading_period_id'])]
class Grade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Enrollment $enrollment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Subject $subject;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GradingPeriod $gradingPeriod;

    #[ORM\Column(type: 'smallint')]
    private int $value;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $updatedBy;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Enrollment $enrollment, Subject $subject, GradingPeriod $gradingPeriod, int $value, User $by, \DateTimeImmutable $at)
    {
        $this->enrollment = $enrollment;
        $this->subject = $subject;
        $this->gradingPeriod = $gradingPeriod;
        $this->value = $value;
        $this->updatedBy = $by;
        $this->updatedAt = $at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnrollment(): Enrollment
    {
        return $this->enrollment;
    }

    public function getSubject(): Subject
    {
        return $this->subject;
    }

    public function getGradingPeriod(): GradingPeriod
    {
        return $this->gradingPeriod;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function change(int $value, User $by, \DateTimeImmutable $at): static
    {
        $this->value = $value;
        $this->updatedBy = $by;
        $this->updatedAt = $at;

        return $this;
    }

    public function getUpdatedBy(): User
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
