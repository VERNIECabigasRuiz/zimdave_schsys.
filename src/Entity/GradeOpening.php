<?php

namespace App\Entity;

use App\Repository\GradeOpeningRepository;
use Doctrine\ORM\Mapping as ORM;

/** A school's switch for grading: while it exists teachers of the school can record grades for that period. */
#[ORM\Entity(repositoryClass: GradeOpeningRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_grade_opening', columns: ['school_id', 'grading_period_id'])]
class GradeOpening
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private School $school,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private GradingPeriod $gradingPeriod,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $openedBy,
        #[ORM\Column]
        private \DateTimeImmutable $openedAt,
    ) {
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

    public function getOpenedBy(): ?User
    {
        return $this->openedBy;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }
}
