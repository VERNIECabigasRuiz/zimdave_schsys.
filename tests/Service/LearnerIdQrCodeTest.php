<?php

namespace App\Tests\Service;

use App\LearnerId\QrCode;
use PHPUnit\Framework\TestCase;

class LearnerIdQrCodeTest extends TestCase
{
    public function testPngDataUri(): void
    {
        $uri = (new QrCode())->png('120017170001');

        self::assertStringStartsWith('data:image/png;base64,', $uri);
        $png = base64_decode(substr($uri, \strlen('data:image/png;base64,')), true);
        self::assertStringStartsWith("\x89PNG", $png);
        [$width, $height] = getimagesizefromstring($png);
        self::assertGreaterThanOrEqual(150, $width);
        self::assertSame($width, $height);
    }
}
