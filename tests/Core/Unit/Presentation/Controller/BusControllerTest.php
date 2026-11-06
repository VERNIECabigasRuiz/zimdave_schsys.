<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Presentation\Controller;

use App\Core\Application\Bus\Result;
use App\Core\Domain\Exception\ResourceBusyException;
use App\Tests\Core\Unit\Presentation\Controller\Fixture\ExposedBusController;
use PHPUnit\Framework\TestCase;

final class BusControllerTest extends TestCase
{
    public function testSuccessGivesNull(): void
    {
        // Execute
        $failure = $this->controller()->failureOf(Result::success('done'));

        // Assert
        self::assertNull($failure);
    }

    public function testDomainFailureIsReturned(): void
    {
        // Initialize
        $busy = new ResourceBusyException('Please try again.');

        // Execute
        $failure = $this->controller()->failureOf(Result::failure($busy, $busy->errorCode()));

        // Assert
        self::assertSame($busy, $failure);
    }

    public function testUnexpectedFailureIsRethrown(): void
    {
        // Expect
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Database is down.');

        // Execute
        $this->controller()->failureOf(Result::failure(new \RuntimeException('Database is down.'), 'INTERNAL_ERROR'));
    }

    private function controller(): ExposedBusController
    {
        return new ExposedBusController();
    }
}
