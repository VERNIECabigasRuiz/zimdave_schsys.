<?php

declare(strict_types=1);

namespace App\Announcements\Application\UseCase\Command;

use App\Access\Domain\Model\User;
use App\Announcements\Domain\Model\Announcement;
use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/** An admin posts an announcement. Bound to the announcement form. */
final class PostAnnouncementCommand implements UseCase
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    public string $title = '';

    #[Assert\Choice(choices: ['all', User::ROLE_PRINCIPAL, User::ROLE_TEACHER, User::ROLE_STUDENT])]
    public string $audience = Announcement::AUDIENCE_ALL;

    #[Assert\NotBlank]
    public string $body = '';

    public function __construct(public readonly int $authorId)
    {
    }

    public function toLogArray(): array
    {
        return ['authorId' => $this->authorId, 'audience' => $this->audience];
    }
}
