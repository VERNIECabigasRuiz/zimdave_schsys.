<?php

namespace App\Entity;

use App\Repository\CardSlipCommentRepository;
use Doctrine\ORM\Mapping as ORM;

/** The adviser's chosen comments (built-in codes plus one typed comment) for a learner's card slip in one term. */
#[ORM\Entity(repositoryClass: CardSlipCommentRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_card_slip_comment', columns: ['enrollment_id', 'grading_period_id'])]
class CardSlipComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $codes = [];

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $customText = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Enrollment $enrollment,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private GradingPeriod $gradingPeriod,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnrollment(): Enrollment
    {
        return $this->enrollment;
    }

    public function getGradingPeriod(): GradingPeriod
    {
        return $this->gradingPeriod;
    }

    /** @return list<string> */
    public function getCodes(): array
    {
        return $this->codes;
    }

    public function getCustomText(): ?string
    {
        return $this->customText;
    }

    /** @param list<string> $codes */
    public function update(array $codes, ?string $customText, User $by, \DateTimeImmutable $at): void
    {
        $this->codes = array_values($codes);
        $this->customText = $customText;
        $this->updatedBy = $by;
        $this->updatedAt = $at;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
