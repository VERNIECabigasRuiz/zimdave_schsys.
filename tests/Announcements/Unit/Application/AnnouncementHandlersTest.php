<?php

declare(strict_types=1);

namespace App\Tests\Announcements\Unit\Application;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Announcements\Application\Query\Command\GetAnnouncementCommand;
use App\Announcements\Application\Query\Command\ListAnnouncementsCommand;
use App\Announcements\Application\Query\Handler\GetAnnouncementHandler;
use App\Announcements\Application\Query\Handler\ListAnnouncementsHandler;
use App\Announcements\Application\Transformer\AnnouncementTransformer;
use App\Announcements\Application\UseCase\Command\DeleteAnnouncementCommand;
use App\Announcements\Application\UseCase\Command\PostAnnouncementCommand;
use App\Announcements\Application\UseCase\Command\UpdateAnnouncementCommand;
use App\Announcements\Application\UseCase\Handler\DeleteAnnouncementHandler;
use App\Announcements\Application\UseCase\Handler\PostAnnouncementHandler;
use App\Announcements\Application\UseCase\Handler\UpdateAnnouncementHandler;
use App\Announcements\Domain\Exception\AnnouncementNotFoundException;
use App\Announcements\Domain\Model\Announcement;
use App\Announcements\Domain\Model\Repository\AnnouncementRepositoryInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use PHPUnit\Framework\TestCase;

final class AnnouncementHandlersTest extends TestCase
{
    public function testPostsAnAnnouncement(): void
    {
        // Initialize
        $author = (new User())->setUsername('ana');
        $accounts = self::createStub(AccountDirectoryInterface::class);
        $accounts->method('findById')->willReturnMap([[5, $author]]);
        $added = null;
        $announcements = $this->createMock(AnnouncementRepositoryInterface::class);
        $announcements->expects(self::once())->method('add')
            ->willReturnCallback(static function (Announcement $announcement) use (&$added): void {
                $added = $announcement;
            });
        $command = new PostAnnouncementCommand(5);
        $command->title = ' Grades due ';
        $command->audience = User::ROLE_TEACHER;
        $command->body = 'Friday';
        $logger = $this->logger('Posted announcement: Grades due');
        $handler = new PostAnnouncementHandler($announcements, $accounts, $logger);

        // Execute
        $result = $handler($command);

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertInstanceOf(Announcement::class, $added);
        self::assertSame('Grades due', $added->getTitle());
        self::assertSame('Friday', $added->getBody());
        self::assertSame(User::ROLE_TEACHER, $added->getAudience());
        self::assertSame('ana', $added->getAuthor()?->getUsername());
        self::assertSame(['authorId' => 5, 'audience' => User::ROLE_TEACHER], $command->toLogArray());
    }

    public function testPostNeedsAnExistingAuthor(): void
    {
        // Expect
        $this->expectException(\LogicException::class);

        // Initialize
        $accounts = self::createStub(AccountDirectoryInterface::class);
        $accounts->method('findById')->willReturn(null);
        $handler = new PostAnnouncementHandler($this->announcements(null), $accounts, $this->logger());

        // Execute
        $handler(new PostAnnouncementCommand(5));
    }

    public function testUpdateSavesTheChanges(): void
    {
        // Initialize
        $author = (new User())->setUsername('ana');
        $announcement = $this->announcement(7, 'Grades due')->setAuthor($author);
        $command = UpdateAnnouncementCommand::fromAnnouncement(AnnouncementTransformer::toArray($announcement));
        $command->title = 'Grades due Monday';
        $command->audience = User::ROLE_STUDENT;
        $command->body = 'Monday';
        $handler = new UpdateAnnouncementHandler(
            $this->announcements($announcement),
            $this->logger('Updated announcement: Grades due Monday'),
        );

        // Execute
        $result = $handler($command);

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertSame('Grades due Monday', $announcement->getTitle());
        self::assertSame('Monday', $announcement->getBody());
        self::assertSame(User::ROLE_STUDENT, $announcement->getAudience());
        self::assertSame('ana', $announcement->getAuthor()?->getUsername());
        self::assertSame(['id' => 7, 'audience' => User::ROLE_STUDENT], $command->toLogArray());
    }

    public function testUpdateRefusesAnUnknownAnnouncement(): void
    {
        // Expect
        $this->expectException(AnnouncementNotFoundException::class);

        // Initialize
        $command = UpdateAnnouncementCommand::fromAnnouncement(
            AnnouncementTransformer::toArray($this->announcement(7, 'Gone')),
        );
        $handler = new UpdateAnnouncementHandler($this->announcements(null), $this->logger());

        // Execute
        $handler($command);
    }

    public function testDeletesAnAnnouncement(): void
    {
        // Initialize
        $announcement = $this->announcement(7, 'Grades due');
        $announcements = $this->createMock(AnnouncementRepositoryInterface::class);
        $announcements->method('findById')->willReturnMap([[7, $announcement]]);
        $announcements->expects(self::once())->method('remove')->with($announcement);
        $command = new DeleteAnnouncementCommand(7);
        $handler = new DeleteAnnouncementHandler($announcements, $this->logger('Deleted announcement: Grades due'));

        // Execute
        $result = $handler($command);

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertSame(['id' => 7], $command->toLogArray());
    }

    public function testDeleteRefusesAnUnknownAnnouncement(): void
    {
        // Expect
        $this->expectException(AnnouncementNotFoundException::class);
        $this->expectExceptionMessageIsOrContains('The announcement was not found.');

        // Initialize
        $handler = new DeleteAnnouncementHandler($this->announcements(null), $this->logger());

        // Execute
        $handler(new DeleteAnnouncementCommand(7));
    }

    public function testListsNewestFirst(): void
    {
        // Initialize
        $announcements = self::createStub(AnnouncementRepositoryInterface::class);
        $announcements->method('findNewestFirst')
            ->willReturn([$this->announcement(9, 'Newer'), $this->announcement(4, 'Older')]);
        $command = new ListAnnouncementsCommand();

        // Execute
        $result = (new ListAnnouncementsHandler($announcements))($command);

        // Assert
        $list = $result->data();
        self::assertIsArray($list);
        self::assertSame([9, 4], array_column($list, 'id'));
        self::assertSame([], $command->toLogArray());
    }

    public function testGetsOneAnnouncement(): void
    {
        // Initialize
        $author = (new User())->setFullName('Ana Cruz');
        $announcement = $this->announcement(7, 'Grades due')->setAuthor($author)->setAudience(User::ROLE_TEACHER);
        $command = new GetAnnouncementCommand(7);

        // Execute
        $result = (new GetAnnouncementHandler($this->announcements($announcement)))($command);

        // Assert
        self::assertSame([
            'id' => 7,
            'title' => 'Grades due',
            'body' => 'Message',
            'audience' => User::ROLE_TEACHER,
            'audienceLabel' => 'Teachers',
            'authorName' => 'Ana Cruz',
            'createdAt' => $announcement->getCreatedAt(),
        ], $result->data());
        self::assertSame(['id' => 7], $command->toLogArray());
    }

    public function testGetRefusesAnUnknownAnnouncement(): void
    {
        // Expect
        $this->expectException(AnnouncementNotFoundException::class);

        // Initialize
        $handler = new GetAnnouncementHandler($this->announcements(null));

        // Execute
        $handler(new GetAnnouncementCommand(7));
    }

    public function testAnAnnouncementWithoutAuthorHasNoAuthorName(): void
    {
        // Initialize
        $announcement = $this->announcement(7, 'Grades due');

        // Execute
        $data = AnnouncementTransformer::toArray($announcement);

        // Assert
        self::assertNull($data['authorName']);
    }

    private function announcement(int $id, string $title): Announcement
    {
        $announcement = (new Announcement())->setTitle($title)->setBody('Message');
        (new \ReflectionProperty(Announcement::class, 'id'))->setValue($announcement, $id);

        return $announcement;
    }

    private function announcements(?Announcement $byId): AnnouncementRepositoryInterface
    {
        $announcements = self::createStub(AnnouncementRepositoryInterface::class);
        $announcements->method('findById')->willReturn($byId);

        return $announcements;
    }

    private function logger(?string $expectedAction = null): ActivityLoggerInterface
    {
        if (null === $expectedAction) {
            return self::createStub(ActivityLoggerInterface::class);
        }
        $logger = $this->createMock(ActivityLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with($expectedAction);

        return $logger;
    }
}
