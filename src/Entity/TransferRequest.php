<?php

namespace App\Entity;

use App\Repository\TransferRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/** A request to move an enrolled learner to a section of another school. */
#[ORM\Entity(repositoryClass: TransferRequestRepository::class)]
class TransferRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Learner $learner;

    /** Null once the learner is unenrolled; the request keeps the learner and where they were as history. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Enrollment $enrollment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private School $fromSchool;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Section $fromSection;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Section $toSection;

    #[ORM\Column(length: 10, enumType: TransferStatus::class)]
    private TransferStatus $status = TransferStatus::Pending;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $requestedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct(Enrollment $enrollment, Section $toSection, User $requestedBy, \DateTimeImmutable $createdAt)
    {
        $this->enrollment = $enrollment;
        $this->learner = $enrollment->getLearner();
        $this->fromSchool = $enrollment->getSchool();
        $this->fromSection = $enrollment->getSection();
        $this->toSection = $toSection;
        $this->requestedBy = $requestedBy;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLearner(): Learner
    {
        return $this->learner;
    }

    public function getEnrollment(): ?Enrollment
    {
        return $this->enrollment;
    }

    public function getFromSchool(): School
    {
        return $this->fromSchool;
    }

    public function getFromSection(): Section
    {
        return $this->fromSection;
    }

    public function getToSection(): Section
    {
        return $this->toSection;
    }

    public function getToSchool(): School
    {
        return $this->toSection->getSchool();
    }

    public function getStatus(): TransferStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return TransferStatus::Pending === $this->status;
    }

    public function getRequestedBy(): User
    {
        return $this->requestedBy;
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

    public function decide(TransferStatus $status, User $by, \DateTimeImmutable $at): static
    {
        $this->status = $status;
        $this->decidedBy = $by;
        $this->decidedAt = $at;

        return $this;
    }
}
