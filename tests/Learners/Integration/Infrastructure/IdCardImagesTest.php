<?php

declare(strict_types=1);

namespace App\Tests\Learners\Integration\Infrastructure;

use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\Learners\Infrastructure\LearnerId\IdCardImages;
use App\Learners\Infrastructure\LearnerId\QrCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IdCardImagesTest extends KernelTestCase
{
    public function testAStoredLogoIsInlinedAsAPngAndAMissingOneIsNull(): void
    {
        // Initialize
        self::bootKernel();
        $images = static::getContainer()->get(IdCardImages::class);
        $path = 'uploads/logos/' . bin2hex(random_bytes(16)) . '.webp';
        $file = static::getContainer()->getParameter('app.upload_root') . '/' . $path;
        @mkdir(\dirname($file), 0777, true);
        imagewebp(imagecreatetruecolor(40, 40), $file);

        // Execute
        try {
            $logo = $images->logo($path);
        } finally {
            unlink($file);
        }
        $missing = $images->logo('uploads/logos/missing.webp');

        // Assert
        self::assertStringStartsWith('data:image/png;base64,', (string) $logo);
        self::assertNull($missing);
    }

    public function testWithoutTheSealFileThereIsNoSeal(): void
    {
        // Initialize
        self::bootKernel();
        $images = new IdCardImages(
            static::getContainer()->get(ImageProcessorInterface::class),
            new QrCode(),
            sys_get_temp_dir() . '/no-project-' . bin2hex(random_bytes(4)),
        );

        // Execute
        $seal = $images->seal();

        // Assert
        self::assertNull($seal);
    }
}
