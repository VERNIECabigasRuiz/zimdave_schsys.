<?php

namespace App\Entity;

use App\Repository\GradeUnlockRepository;
use Doctrine\ORM\Mapping as ORM;

/** The principal's permission to correct one subject's grades of one class in a locked grading period. */
#[ORM\Entity(repositoryClass: GradeUnlockRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_grade_unlock', columns: ['section_id', 'subject_id', 'grading_period_id'])]
class GradeUnlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Section $section;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Subject $subject;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GradingPeriod $gradingPeriod;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $unlockedBy;

    #[ORM\Column]
    private \DateTimeImmutable $unlockedAt;

    public function __construct(Section $section, Subject $subject, GradingPeriod $gradingPeriod, User $unlockedBy, \DateTimeImmutable $unlockedAt)
    {
        $this->section = $section;
        $this->subject = $subject;
        $this->gradingPeriod = $gradingPeriod;
        $this->unlockedBy = $unlockedBy;
        $this->unlockedAt = $unlockedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function getSubject(): Subject
    {
        return $this->subject;
    }

    public function getGradingPeriod(): GradingPeriod
    {
        return $this->gradingPeriod;
    }

    public function getUnlockedBy(): User
    {
        return $this->unlockedBy;
    }

    public function getUnlockedAt(): \DateTimeImmutable
    {
        return $this->unlockedAt;
    }
}
