<?php

declare(strict_types=1);

namespace App\Announcements\Domain\Model;

use App\Access\Domain\Model\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Announcement
{
    public const AUDIENCE_ALL = 'all';

    /** Who can see the announcement. */
    public const AUDIENCES = [
        self::AUDIENCE_ALL => 'Everyone',
        User::ROLE_PRINCIPAL => 'Principals',
        User::ROLE_TEACHER => 'Teachers',
        User::ROLE_STUDENT => 'Students',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    private string $body = '';

    #[ORM\Column(length: 20)]
    private string $audience = self::AUDIENCE_ALL;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = trim($body);

        return $this;
    }

    public function getAudience(): string
    {
        return $this->audience;
    }

    public function setAudience(string $audience): static
    {
        $this->audience = $audience;

        return $this;
    }

    public function getAudienceLabel(): string
    {
        return self::AUDIENCES[$this->audience] ?? $this->audience;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
