<?php

declare(strict_types=1);

namespace App\Tests\Staff\Integration\Infrastructure;

use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\Staff\Infrastructure\StaffId\StaffIdImages;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StaffIdImagesTest extends KernelTestCase
{
    public function testStoredImagesAreInlinedAndMissingOnesAreNull(): void
    {
        // Initialize
        self::bootKernel();
        $images = static::getContainer()->get(StaffIdImages::class);
        $path = 'uploads/photos/' . bin2hex(random_bytes(16)) . '.webp';
        $file = static::getContainer()->getParameter('app.upload_root') . '/' . $path;
        @mkdir(\dirname($file), 0777, true);
        imagewebp(imagecreatetruecolor(40, 40), $file);

        // Execute
        try {
            $photo = $images->photo($path);
            $logo = $images->logo($path);
            $signature = $images->signature($path);
        } finally {
            unlink($file);
        }
        $missing = $images->photo('uploads/photos/missing.webp');
        $none = $images->signature(null);

        // Assert
        self::assertStringStartsWith('data:image/jpeg;base64,', (string) $photo);
        self::assertStringStartsWith('data:image/png;base64,', (string) $logo);
        self::assertStringStartsWith('data:image/png;base64,', (string) $signature);
        self::assertNull($missing);
        self::assertNull($none);
    }

    public function testTheSealIsInlinedOnlyWhenItsFileExists(): void
    {
        // Initialize
        self::bootKernel();
        $processor = static::getContainer()->get(ImageProcessorInterface::class);
        $withSeal = static::getContainer()->get(StaffIdImages::class);
        $withoutSeal = new StaffIdImages($processor, sys_get_temp_dir() . '/no-project-' . bin2hex(random_bytes(4)));

        // Execute
        $seal = $withSeal->seal();
        $noSeal = $withoutSeal->seal();

        // Assert
        self::assertStringStartsWith('data:image/png;base64,', (string) $seal);
        self::assertNull($noSeal);
    }
}
