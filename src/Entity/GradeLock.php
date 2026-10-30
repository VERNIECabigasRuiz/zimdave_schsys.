<?php

namespace App\Entity;

use App\Repository\GradeLockRepository;
use Doctrine\ORM\Mapping as ORM;

/** The principal's lock on a grading period: while it exists no grade of the school can change for that period. */
#[ORM\Entity(repositoryClass: GradeLockRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_grade_lock', columns: ['school_id', 'grading_period_id'])]
class GradeLock
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
    private GradingPeriod $gradingPeriod;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $lockedBy;

    #[ORM\Column]
    private \DateTimeImmutable $lockedAt;

    public function __construct(School $school, GradingPeriod $gradingPeriod, User $lockedBy, \DateTimeImmutable $lockedAt)
    {
        $this->school = $school;
        $this->gradingPeriod = $gradingPeriod;
        $this->lockedBy = $lockedBy;
        $this->lockedAt = $lockedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSchool(): School
    {
        return $this->school;
    }

    public function getGradingPeriod(): GradingPeriod
    {
        return $this->gradingPeriod;
    }

    public function getLockedBy(): User
    {
        return $this->lockedBy;
    }

    public function getLockedAt(): \DateTimeImmutable
    {
        return $this->lockedAt;
    }
}
