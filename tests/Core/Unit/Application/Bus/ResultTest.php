<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Application\Bus;

use App\Core\Application\Bus\Result;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function testSuccessCarriesData(): void
    {
        // Execute
        $result = Result::success(['a' => 1]);

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertFalse($result->isFailure());
        self::assertSame(['a' => 1], $result->data());
        self::assertNull($result->exception());
        self::assertNull($result->errorCode());
    }

    public function testSuccessDefaultsToNullData(): void
    {
        // Execute
        $result = Result::success();

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertNull($result->data());
    }

    public function testFailureCarriesExceptionAndCode(): void
    {
        // Initialize
        $exception = new \RuntimeException('broken');

        // Execute
        $result = Result::failure($exception, 'X_CODE');

        // Assert
        self::assertTrue($result->isFailure());
        self::assertFalse($result->isSuccess());
        self::assertSame($exception, $result->exception());
        self::assertSame('X_CODE', $result->errorCode());
        self::assertNull($result->data());
    }
}
