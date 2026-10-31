<?php

namespace App\Entity;

use App\Repository\GradeChangeRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A teacher's request to correct some grades of a locked grading period; the principal approves or declines it. */
#[ORM\Entity(repositoryClass: GradeChangeRequestRepository::class)]
#[ORM\Index(name: 'idx_grade_change_class', columns: ['section_id', 'subject_id', 'grading_period_id', 'status'])]
class GradeChangeRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Section $section;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Subject $subject;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GradingPeriod $gradingPeriod;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $requestedBy;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(length: 10, enumType: GradeChangeStatus::class)]
    private GradeChangeStatus $status = GradeChangeStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decisionNote = null;

    /** @var Collection<int, GradeChangeLine> */
    #[ORM\OneToMany(mappedBy: 'request', targetEntity: GradeChangeLine::class, cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    public function __construct(Section $section, Subject $subject, GradingPeriod $gradingPeriod, User $requestedBy, string $reason, \DateTimeImmutable $createdAt)
    {
        $this->section = $section;
        $this->subject = $subject;
        $this->gradingPeriod = $gradingPeriod;
        $this->requestedBy = $requestedBy;
        $this->reason = $reason;
        $this->createdAt = $createdAt;
        $this->lines = new ArrayCollection();
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

    public function getRequestedBy(): User
    {
        return $this->requestedBy;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getStatus(): GradeChangeStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return GradeChangeStatus::Pending === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    /** @return Collection<int, GradeChangeLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    /** For building a pending request. */
    public function addLine(Enrollment $enrollment, ?int $from, int $to): void
    {
        $this->lines->add(new GradeChangeLine($this, $enrollment, $from, $to));
    }

    public function approve(User $by, \DateTimeImmutable $at): void
    {
        $this->leavePending(GradeChangeStatus::Approved, $at);
        $this->decidedBy = $by;
    }

    public function decline(User $by, \DateTimeImmutable $at, string $note): void
    {
        $this->leavePending(GradeChangeStatus::Declined, $at);
        $this->decidedBy = $by;
        $this->decisionNote = $note;
    }

    public function withdraw(\DateTimeImmutable $at): void
    {
        $this->leavePending(GradeChangeStatus::Withdrawn, $at);
    }

    private function leavePending(GradeChangeStatus $status, \DateTimeImmutable $at): void
    {
        if (!$this->isPending()) {
            throw new \LogicException('This request has already been decided.');
        }
        $this->status = $status;
        $this->decidedAt = $at;
    }
}
