<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Infrastructure\FileStorage;

use App\Core\Domain\Exception\ImageProcessingException;
use App\Core\Infrastructure\FileStorage\ImageProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ImageProcessorTest extends KernelTestCase
{
    private ImageProcessor $images;
    private string $tmp;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->images = new ImageProcessor(static::getContainer()->getParameter('app.upload_root'), new Filesystem());
        $this->tmp = sys_get_temp_dir() . '/zim_img_' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/*') ?: []);
        rmdir($this->tmp);
        parent::tearDown();
    }

    public function testPhotoIsCroppedTo350x450Webp(): void
    {
        // Execute
        $path = $this->images->storePhoto($this->jpeg(1000, 600), null);

        // Assert
        self::assertMatchesRegularExpression('#^uploads/photos/[0-9a-f]{32}\.webp$#', $path);
        $info = $this->imageSize($this->images->absolutePath($path));
        self::assertSame([350, 450, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        $this->images->remove($path);
    }

    public function testLogoKeepsAspectWithin400AndTransparency(): void
    {
        // Execute
        $path = $this->images->storeLogo($this->transparentPng(800, 200), null);

        // Assert
        self::assertMatchesRegularExpression('#^uploads/logos/[0-9a-f]{32}\.webp$#', $path);
        $absolute = $this->images->absolutePath($path);
        $info = $this->imageSize($absolute);
        self::assertSame([400, 100, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        $img = $this->webp($absolute);
        self::assertSame(127, ((int) imagecolorat($img, 0, 0) >> 24) & 0x7F, 'Corner pixel should stay transparent');
        $this->images->remove($path);
    }

    public function testReplacingDeletesOldFile(): void
    {
        // Initialize
        $old = $this->images->storePhoto($this->jpeg(400, 400), null);

        // Execute
        $new = $this->images->storePhoto($this->jpeg(400, 400), $old);

        // Assert
        self::assertFileDoesNotExist($this->images->absolutePath($old));
        self::assertFileExists($this->images->absolutePath($new));
        $this->images->remove($new);
    }

    public function testReplacingASignatureDeletesTheOldOne(): void
    {
        // Initialize
        $old = $this->images->storeSignature($this->transparentPng(900, 150), null);

        // Execute
        $new = $this->images->storeSignature($this->transparentPng(900, 150), $old);

        // Assert
        self::assertMatchesRegularExpression('#^uploads/signatures/[0-9a-f]{32}\.webp$#', $new);
        self::assertSame(600, $this->imageSize($this->images->absolutePath($new))[0]);
        self::assertFileDoesNotExist($this->images->absolutePath($old));
        $this->images->remove($new);
    }

    public function testRemoveDeletesFile(): void
    {
        // Initialize
        $path = $this->images->storePhoto($this->jpeg(400, 400), null);

        // Execute
        $this->images->remove($path);
        $this->images->remove(null);

        // Assert
        self::assertFileDoesNotExist($this->images->absolutePath($path));
    }

    public function testUndecodableFileThrowsAndKeepsOld(): void
    {
        // Initialize
        $old = $this->images->storePhoto($this->jpeg(400, 400), null);
        file_put_contents($this->tmp . '/fake.jpg', 'not really an image');

        // Execute
        try {
            $this->images->storePhoto($this->tmp . '/fake.jpg', $old);
            self::fail('Expected ImageProcessingException');
        } catch (ImageProcessingException) {
            // Assert
            self::assertFileExists($this->images->absolutePath($old));
        }
        $this->images->remove($old);
    }

    public function testRejectionCarriesItsErrorCode(): void
    {
        // Initialize
        file_put_contents($this->tmp . '/fake.jpg', 'not really an image');

        // Execute
        try {
            $this->images->storePhoto($this->tmp . '/fake.jpg', null);
            self::fail('Expected ImageProcessingException');
        } catch (ImageProcessingException $e) {
            // Assert
            self::assertSame('IMAGE_NOT_ACCEPTED', $e->errorCode());
            self::assertSame('The file is not a readable image.', $e->getMessage());
        }
    }

    public function testStoresAFileWithoutExtension(): void
    {
        // Initialize
        $upload = $this->tmp . '/phpAbC123';
        rename($this->jpeg(400, 400), $upload);

        // Execute
        $path = $this->images->storePhoto($upload, null);

        // Assert
        self::assertMatchesRegularExpression('#^uploads/photos/[0-9a-f]{32}\.webp$#', $path);
        self::assertFileExists($this->images->absolutePath($path));
        $this->images->remove($path);
    }

    /** A tiny PNG whose header claims a huge size (decompression bomb). */
    public static function hugePngHeader(string $dir): string
    {
        $img = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        // IHDR data starts at byte 16: width (4), height (4); its CRC follows the 13 data bytes
        $ihdr = pack('NN', 12000, 12000) . substr($png, 24, 5);
        $png = substr($png, 0, 16) . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . substr($png, 33);
        $path = $dir . '/bomb_' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($path, $png);

        return $path;
    }

    public function testHugeDimensionsAreRejectedBeforeDecoding(): void
    {
        // Expect
        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessageIsOrContains('too large');

        // Execute
        $this->images->storePhoto(self::hugePngHeader($this->tmp), null);
    }

    public function testExifOrientationIsApplied(): void
    {
        // Execute
        $path = $this->images->storePhoto($this->rotatedPhoneJpeg(), null);

        // Assert
        $img = $this->webp($this->images->absolutePath($path));
        $top = imagecolorsforindex($img, (int) imagecolorat($img, 175, 30));
        $bottom = imagecolorsforindex($img, (int) imagecolorat($img, 175, 420));
        self::assertGreaterThan(200, $top['red'], 'Top should be the red (left) half after rotating');
        self::assertGreaterThan(200, $bottom['blue'], 'Bottom should be the blue (right) half after rotating');
        $this->images->remove($path);
    }

    /** @return iterable<string, array{int}> */
    public static function orientations(): iterable
    {
        foreach (range(1, 8) as $orientation) {
            yield 'EXIF orientation ' . $orientation => [$orientation];
        }
    }

    #[DataProvider('orientations')]
    public function testEveryExifOrientationEndsUpright(int $orientation): void
    {
        // Initialize
        $source = $this->taggedJpeg($orientation);

        // Execute
        $path = $this->images->storePhoto($source, null);

        // Assert
        $img = $this->webp($this->images->absolutePath($path));
        $topLeft = imagecolorsforindex($img, (int) imagecolorat($img, 80, 100));
        $topRight = imagecolorsforindex($img, (int) imagecolorat($img, 270, 100));
        $bottom = imagecolorsforindex($img, (int) imagecolorat($img, 175, 400));
        self::assertGreaterThan(200, $topLeft['red'], 'Top left should be red');
        self::assertGreaterThan(200, $topRight['green'], 'Top right should be green');
        self::assertGreaterThan(200, $bottom['blue'], 'Bottom should be blue');
        $this->images->remove($path);
    }

    public function testRemoveIgnoresPathsOutsideUploads(): void
    {
        // Initialize
        $keep = $this->images->absolutePath('keep.txt');
        @mkdir(\dirname($keep), 0o777, true);
        file_put_contents($keep, 'x');

        // Execute
        $this->images->remove('keep.txt');
        $this->images->remove('uploads/photos/../../keep.txt');

        // Assert
        self::assertFileExists($keep);
        unlink($keep);
    }

    /**
     * @param int<1, max> $w
     * @param int<1, max> $h
     */
    private function jpeg(int $w, int $h): string
    {
        $img = $this->canvas($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 30, 30));
        $path = $this->tmp . '/' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg($img, $path);

        return $path;
    }

    /**
     * @param int<1, max> $w
     * @param int<1, max> $h
     */
    private function transparentPng(int $w, int $h): string
    {
        $img = $this->canvas($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));
        $blue = (int) imagecolorallocate($img, 61, 90, 254);
        imagefilledrectangle($img, (int) ($w / 4), (int) ($h / 4), (int) ($w * 3 / 4), (int) ($h * 3 / 4), $blue);
        $path = $this->tmp . '/' . bin2hex(random_bytes(4)) . '.png';
        imagepng($img, $path);

        return $path;
    }

    /** Landscape JPEG, left half red / right half blue, tagged EXIF Orientation 6 (rotate 90° CW to view). */
    private function rotatedPhoneJpeg(): string
    {
        $img = $this->canvas(400, 300);
        imagefilledrectangle($img, 0, 0, 199, 299, (int) imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 200, 0, 399, 299, (int) imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 95);
        $jpeg = (string) ob_get_clean();

        $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
        $app1 = "Exif\0\0" . $tiff;
        $jpeg = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', \strlen($app1) + 2) . $app1 . substr($jpeg, 2);
        $path = $this->tmp . '/phone.jpg';
        file_put_contents($path, $jpeg);

        return $path;
    }

    /**
     * A phone JPEG that shows, once its EXIF orientation is applied, red top left, green top right and blue
     * below. The stored pixels are the upright picture turned back the way that orientation describes.
     */
    private function taggedJpeg(int $orientation): string
    {
        $img = $this->canvas(300, 400);
        imagefilledrectangle($img, 0, 0, 149, 199, (int) imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 150, 0, 299, 199, (int) imagecolorallocate($img, 0, 255, 0));
        imagefilledrectangle($img, 0, 200, 299, 399, (int) imagecolorallocate($img, 0, 0, 255));
        if (\in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($img, \IMG_FLIP_HORIZONTAL);
        }
        // The processor turns counter-clockwise by these angles, so the stored image is turned the other way
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 90,
            7, 8 => 270,
            default => 0,
        };
        $stored = 0 === $angle ? $img : imagerotate($img, $angle, 0);
        self::assertInstanceOf(\GdImage::class, $stored);
        ob_start();
        imagejpeg($stored, null, 95);
        $jpeg = (string) ob_get_clean();

        $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientation, 0) . pack('V', 0);
        $app1 = "Exif\0\0" . $tiff;
        $jpeg = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', \strlen($app1) + 2) . $app1 . substr($jpeg, 2);
        $path = $this->tmp . '/tagged_' . $orientation . '.jpg';
        file_put_contents($path, $jpeg);

        return $path;
    }

    /**
     * @param int<1, max> $w
     * @param int<1, max> $h
     */
    private function canvas(int $w, int $h): \GdImage
    {
        return imagecreatetruecolor($w, $h);
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, mime: string, channels?: int, bits?: int} */
    private function imageSize(string $path): array
    {
        $info = getimagesize($path);
        self::assertIsArray($info);

        return $info;
    }

    private function webp(string $path): \GdImage
    {
        $img = imagecreatefromwebp($path);
        self::assertInstanceOf(\GdImage::class, $img);

        return $img;
    }
}
