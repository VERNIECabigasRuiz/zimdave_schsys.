<?php

namespace App\Entity;

use App\Repository\AiDraftRepository;
use Doctrine\ORM\Mapping as ORM;

/** One successful AI draft: what counts against the monthly limit, and what it cost in tokens. */
#[ORM\Entity(repositoryClass: AiDraftRepository::class)]
#[ORM\Index(name: 'idx_ai_draft_user_at', columns: ['user_id', 'created_at'])]
class AiDraft
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private School $school;

    #[ORM\Column(length: 60)]
    private string $model;

    #[ORM\Column]
    private int $inputTokens;

    #[ORM\Column]
    private int $outputTokens;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, School $school, string $model, int $inputTokens, int $outputTokens, \DateTimeImmutable $at)
    {
        $this->user = $user;
        $this->school = $school;
        $this->model = $model;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->createdAt = $at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSchool(): School
    {
        return $this->school;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
