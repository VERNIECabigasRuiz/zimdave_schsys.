<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Infrastructure\ActivityLog;

use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ActivityLoggerTest extends KernelTestCase
{
    private ActivityLoggerInterface $logger;
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->logger = $container->get(ActivityLoggerInterface::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM activity_log');
    }

    public function testUsesTheSignedInUser(): void
    {
        // Initialize
        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken(new InMemoryUser('ana', null), 'main'));

        // Execute
        $this->logger->log('Signed in');
        $this->em->flush();

        // Assert
        self::assertSame([['username' => 'ana', 'action' => 'Signed in']], $this->savedEntries());
    }

    public function testUsesTheGivenUsername(): void
    {
        // Execute
        $this->logger->log('Created account: ana', 'zim');
        $this->em->flush();

        // Assert
        self::assertSame([['username' => 'zim', 'action' => 'Created account: ana']], $this->savedEntries());
    }

    public function testFallsBackToSystem(): void
    {
        // Execute
        $this->logger->log('Enrolled 900000000001');
        $this->em->flush();

        // Assert
        self::assertSame([['username' => 'system', 'action' => 'Enrolled 900000000001']], $this->savedEntries());
    }

    public function testEntryIsSavedOnTheCallersFlush(): void
    {
        // Initialize
        $this->logger->log('Signed in', 'ana');

        // Execute
        $beforeFlush = $this->savedEntries();
        $this->em->flush();

        // Assert
        self::assertSame([], $beforeFlush);
        self::assertCount(1, $this->savedEntries());
    }

    /** @return list<array<string, mixed>> */
    private function savedEntries(): array
    {
        return $this->connection->fetchAllAssociative('SELECT username, action FROM activity_log ORDER BY id');
    }
}
