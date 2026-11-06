<?php

declare(strict_types=1);

namespace App\Tests\Access\Unit\Domain;

use App\Access\Domain\Service\Password\TemporaryPasswordGenerator;
use PHPUnit\Framework\TestCase;

final class TemporaryPasswordGeneratorTest extends TestCase
{
    public function testFormatLeavesOutLookAlikes(): void
    {
        // Initialize
        $generator = new TemporaryPasswordGenerator();

        // Execute
        $passwords = array_map(static fn () => $generator->generate(), range(1, 50));

        // Assert
        foreach ($passwords as $password) {
            self::assertMatchesRegularExpression('/^[a-hj-km-np-z]{4}-[2-9]{4}$/', $password);
        }
    }
}
