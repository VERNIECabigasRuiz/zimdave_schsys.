<?php

declare(strict_types=1);

namespace App\Tests\Announcements\Integration\Infrastructure;

use App\Announcements\Domain\Model\Announcement;
use App\Announcements\Domain\Model\Repository\AnnouncementRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AnnouncementRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AnnouncementRepositoryInterface $announcements;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->announcements = static::getContainer()->get(AnnouncementRepositoryInterface::class);
        $this->em->createQuery('DELETE FROM ' . Announcement::class)->execute();
    }

    public function testFindsById(): void
    {
        // Initialize
        $announcement = $this->announcement('Grades due', '2026-11-01 08:00:00');
        $this->em->flush();

        // Execute
        $found = $this->announcements->findById((int) $announcement->getId());
        $unknown = $this->announcements->findById((int) $announcement->getId() + 1000);

        // Assert
        self::assertNotNull($announcement->getId());
        self::assertSame($announcement->getId(), $found?->getId());
        self::assertNull($unknown);
    }

    public function testNewestFirstBreaksTiesById(): void
    {
        // Initialize
        $older = $this->announcement('Older', '2026-11-01 08:00:00');
        $first = $this->announcement('First', '2026-11-02 08:00:00');
        $second = $this->announcement('Second', '2026-11-02 08:00:00');
        $this->em->flush();

        // Execute
        $ids = array_map(
            static fn (Announcement $announcement) => $announcement->getId(),
            $this->announcements->findNewestFirst(),
        );

        // Assert
        self::assertSame([$second->getId(), $first->getId(), $older->getId()], $ids);
    }

    public function testAddAndRemoveWaitForFlush(): void
    {
        // Initialize
        $announcement = $this->announcement('Temporary', '2026-11-01 08:00:00', false);

        // Execute
        $this->announcements->add($announcement);
        $beforeFlush = $this->rows();
        $this->em->flush();
        $afterAdd = $this->rows();
        $this->announcements->remove($announcement);
        $beforeRemoveFlush = $this->rows();
        $this->em->flush();

        // Assert
        self::assertSame(0, $beforeFlush);
        self::assertSame(1, $afterAdd);
        self::assertSame(1, $beforeRemoveFlush);
        self::assertSame(0, $this->rows());
    }

    private function announcement(string $title, string $createdAt, bool $add = true): Announcement
    {
        $announcement = (new Announcement())->setTitle($title)->setBody('Message');
        (new \ReflectionProperty(Announcement::class, 'createdAt'))
            ->setValue($announcement, new \DateTimeImmutable($createdAt));
        if ($add) {
            $this->announcements->add($announcement);
        }

        return $announcement;
    }

    private function rows(): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM announcement');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
