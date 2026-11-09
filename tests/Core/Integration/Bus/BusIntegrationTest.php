<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus;

use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Tests\Core\Integration\Bus\Fixture\CountPingsHandler;
use App\Tests\Core\Integration\Bus\Fixture\CountPingsQuery;
use App\Tests\Core\Integration\Bus\Fixture\CrashPingCommand;
use App\Tests\Core\Integration\Bus\Fixture\DuplicateUserCommand;
use App\Tests\Core\Integration\Bus\Fixture\LogThenFailCommand;
use App\Tests\Core\Integration\Bus\Fixture\PingCommand;
use App\Tests\Core\Integration\Bus\Fixture\PingRefusedException;
use App\Tests\Core\Integration\Bus\Fixture\RefusePingCommand;
use App\Tests\Core\Integration\Bus\Fixture\UnhandledCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BusIntegrationTest extends KernelTestCase
{
    private UseCaseBusInterface $useCaseBus;
    private QueryBusInterface $queryBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->useCaseBus = $container->get(UseCaseBusInterface::class);
        $this->queryBus = $container->get(QueryBusInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->executeStatement("DELETE FROM activity_log WHERE username = 'bus-test'");
        $this->connection->executeStatement("DELETE FROM `user` WHERE username = 'bus-duplicate'");
        CountPingsHandler::$calls = 0;
    }

    public function testRefusedWriteLeavesTheEntityManagerUsable(): void
    {
        // Execute
        $result = $this->useCaseBus->handle(new DuplicateUserCommand());

        // Assert
        self::assertSame('INTERNAL_ERROR', $result->errorCode());
        self::assertTrue(static::getContainer()->get(EntityManagerInterface::class)->isOpen());
    }

    public function testHandlesUseCase(): void
    {
        // Execute
        $result = $this->useCaseBus->handle(new PingCommand('hi'));

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertSame(['label' => 'hi'], $result->data());
    }

    public function testDomainExceptionBecomesFailure(): void
    {
        // Execute
        $result = $this->useCaseBus->handle(new RefusePingCommand());

        // Assert
        self::assertTrue($result->isFailure());
        self::assertSame('PING_REFUSED', $result->errorCode());
        self::assertInstanceOf(PingRefusedException::class, $result->exception());
    }

    public function testUnexpectedExceptionBecomesInternalError(): void
    {
        // Execute
        $result = $this->useCaseBus->handle(new CrashPingCommand());

        // Assert
        self::assertTrue($result->isFailure());
        self::assertSame('INTERNAL_ERROR', $result->errorCode());
        self::assertInstanceOf(\RuntimeException::class, $result->exception());
    }

    public function testInvalidCommandIsRejectedBeforeTheHandler(): void
    {
        // Execute
        $result = $this->useCaseBus->handle(new PingCommand(''));

        // Assert
        self::assertTrue($result->isFailure());
        self::assertSame('VALIDATION_FAILED', $result->errorCode());
    }

    public function testFailedUseCaseRollsBack(): void
    {
        // Execute
        $result = $this->useCaseBus->handle(new LogThenFailCommand());

        // Assert
        self::assertSame('INTERNAL_ERROR', $result->errorCode());
        $count = $this->connection->fetchOne("SELECT COUNT(*) FROM activity_log WHERE username = 'bus-test'");
        self::assertIsNumeric($count);
        self::assertSame(0, (int) $count);
    }

    public function testCachedQueryRunsHandlerOnce(): void
    {
        // Execute
        $first = $this->queryBus->handle(new CountPingsQuery());
        $second = $this->queryBus->handle(new CountPingsQuery());

        // Assert
        self::assertSame(1, $first->data());
        self::assertSame(1, $second->data());
        self::assertSame(1, CountPingsHandler::$calls);
    }

    public function testUnhandledCommandFails(): void
    {
        // Execute
        $result = $this->useCaseBus->handle(new UnhandledCommand());

        // Assert
        self::assertTrue($result->isFailure());
        self::assertSame('INTERNAL_ERROR', $result->errorCode());
    }

    public function testQueryBusDoesNotRunUseCases(): void
    {
        // Execute
        $result = $this->queryBus->handle(new PingCommand('hi'));

        // Assert
        self::assertTrue($result->isFailure());
        self::assertSame('INTERNAL_ERROR', $result->errorCode());
    }
}
