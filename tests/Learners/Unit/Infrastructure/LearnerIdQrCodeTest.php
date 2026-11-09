<?php

declare(strict_types=1);

namespace App\Tests\Learners\Unit\Infrastructure;

use App\Learners\Infrastructure\LearnerId\QrCode;
use PHPUnit\Framework\TestCase;

class LearnerIdQrCodeTest extends TestCase
{
    public function testPngDataUri(): void
    {
        $uri = (new QrCode())->png('120017170001');

        self::assertStringStartsWith('data:image/png;base64,', $uri);
        $png = base64_decode(substr($uri, \strlen('data:image/png;base64,')), true);
        self::assertNotFalse($png);
        self::assertStringStartsWith("\x89PNG", $png);
        $size = getimagesizefromstring($png);
        self::assertNotFalse($size);
        [$width, $height] = $size;
        self::assertGreaterThanOrEqual(150, $width);
        self::assertSame($width, $height);
    }
}
