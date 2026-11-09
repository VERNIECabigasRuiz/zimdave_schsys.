<?php

declare(strict_types=1);

namespace App\Announcements\Application\UseCase\Command;

use App\Access\Domain\Model\User;
use App\Announcements\Domain\Model\Announcement;
use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An admin edits an announcement. Bound to the announcement form, prefilled from GetAnnouncementCommand.
 *
 * @phpstan-import-type AnnouncementData from \App\Announcements\Application\Transformer\AnnouncementTransformer
 */
final class UpdateAnnouncementCommand implements UseCase
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    public string $title = '';

    #[Assert\Choice(choices: ['all', User::ROLE_PRINCIPAL, User::ROLE_TEACHER, User::ROLE_STUDENT])]
    public string $audience = Announcement::AUDIENCE_ALL;

    #[Assert\NotBlank]
    public string $body = '';

    public function __construct(public readonly int $id)
    {
    }

    /** @param AnnouncementData $announcement */
    public static function fromAnnouncement(array $announcement): self
    {
        $command = new self($announcement['id']);
        $command->title = $announcement['title'];
        $command->audience = $announcement['audience'];
        $command->body = $announcement['body'];

        return $command;
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id, 'audience' => $this->audience];
    }
}
