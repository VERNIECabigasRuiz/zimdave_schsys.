<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Infrastructure\Repository;

use App\Core\Domain\Model\ActivityLog;
use App\Core\Domain\Model\Repository\ActivityLogRepositoryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineActivityLogRepositoryTest extends KernelTestCase
{
    private ActivityLogRepositoryInterface $entries;
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entries = $container->get(ActivityLogRepositoryInterface::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        // Other tests leave entries behind; start from an empty feed
        $this->connection->executeStatement('DELETE FROM activity_log');
    }

    public function testFindRecentIsNewestFirstAndLimited(): void
    {
        // Initialize
        foreach (['first', 'second', 'third'] as $action) {
            $this->entries->record(new ActivityLog('core-test', $action));
        }
        $this->em->flush();
        // The last two share a timestamp, so the higher id decides
        $setTime = 'UPDATE activity_log SET created_at = ? WHERE action IN (?)';
        $types = [1 => ArrayParameterType::STRING];
        $this->connection->executeStatement($setTime, ['2026-11-06 08:00:00', ['first']], $types);
        $this->connection->executeStatement($setTime, ['2026-11-06 09:00:00', ['second', 'third']], $types);
        $this->em->clear();

        // Execute
        $recent = $this->entries->findRecent(2);

        // Assert
        $actions = array_map(static fn (ActivityLog $entry) => $entry->getAction(), $recent);
        self::assertSame(['third', 'second'], $actions);
    }

    public function testRecordWaitsForFlush(): void
    {
        // Initialize
        $entry = new ActivityLog('core-test', 'Signed in');
        $this->entries->record($entry);

        // Execute
        $beforeFlush = $this->savedCount();
        $this->em->flush();
        $afterFlush = $this->savedCount();

        // Assert
        self::assertSame(0, $beforeFlush);
        self::assertSame(1, $afterFlush);
        self::assertNotNull($entry->getId());
    }

    private function savedCount(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM activity_log');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
