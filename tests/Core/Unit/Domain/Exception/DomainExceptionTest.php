<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Domain\Exception;

use App\Core\Domain\Exception\DomainException;
use PHPUnit\Framework\TestCase;

final class DomainExceptionTest extends TestCase
{
    public function testCarriesCodeMessageDataAndPrevious(): void
    {
        // Initialize
        $previous = new \RuntimeException('cause');

        // Execute
        $exception = new class ('Not allowed.', 'SOME_CODE', $previous, ['id' => 5]) extends DomainException {
        };

        // Assert
        self::assertSame('SOME_CODE', $exception->errorCode());
        self::assertSame('Not allowed.', $exception->getMessage());
        self::assertSame(['id' => 5], $exception->data());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(0, $exception->getCode());
    }
}
