<?php

namespace App\Tests\Service;

use App\Service\ImageProcessingException;
use App\Service\ImageProcessor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

class ImageProcessorTest extends KernelTestCase
{
    private ImageProcessor $images;
    private string $tmp;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->images = new ImageProcessor(static::getContainer()->getParameter('app.upload_root'), new Filesystem());
        $this->tmp = sys_get_temp_dir().'/zim_img_'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp.'/*') ?: []);
        rmdir($this->tmp);
        parent::tearDown();
    }

    private function jpeg(int $w, int $h): File
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        $path = $this->tmp.'/'.bin2hex(random_bytes(4)).'.jpg';
        imagejpeg($img, $path);

        return new File($path);
    }

    private function transparentPng(int $w, int $h): File
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, (int) ($w / 4), (int) ($h / 4), (int) ($w * 3 / 4), (int) ($h * 3 / 4), imagecolorallocate($img, 61, 90, 254));
        $path = $this->tmp.'/'.bin2hex(random_bytes(4)).'.png';
        imagepng($img, $path);

        return new File($path);
    }

    public function testPhotoIsCroppedTo350x450Webp(): void
    {
        $path = $this->images->storePhoto($this->jpeg(1000, 600), null);

        self::assertMatchesRegularExpression('#^uploads/photos/[0-9a-f]{32}\.webp$#', $path);
        $info = getimagesize($this->images->absolutePath($path));
        self::assertSame([350, 450, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        $this->images->remove($path);
    }

    public function testLogoKeepsAspectWithin400AndTransparency(): void
    {
        $path = $this->images->storeLogo($this->transparentPng(800, 200), null);

        self::assertMatchesRegularExpression('#^uploads/logos/[0-9a-f]{32}\.webp$#', $path);
        $absolute = $this->images->absolutePath($path);
        $info = getimagesize($absolute);
        self::assertSame([400, 100, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        $img = imagecreatefromwebp($absolute);
        self::assertSame(127, (imagecolorat($img, 0, 0) >> 24) & 0x7F, 'Corner pixel should stay transparent');
        $this->images->remove($path);
    }

    public function testReplacingDeletesOldFile(): void
    {
        $old = $this->images->storePhoto($this->jpeg(400, 400), null);
        $new = $this->images->storePhoto($this->jpeg(400, 400), $old);

        self::assertFileDoesNotExist($this->images->absolutePath($old));
        self::assertFileExists($this->images->absolutePath($new));
        $this->images->remove($new);
    }

    public function testRemoveDeletesFile(): void
    {
        $path = $this->images->storePhoto($this->jpeg(400, 400), null);
        $this->images->remove($path);

        self::assertFileDoesNotExist($this->images->absolutePath($path));
        $this->images->remove(null);
    }

    public function testUndecodableFileThrowsAndKeepsOld(): void
    {
        $old = $this->images->storePhoto($this->jpeg(400, 400), null);
        file_put_contents($this->tmp.'/fake.jpg', 'not really an image');

        try {
            $this->images->storePhoto(new File($this->tmp.'/fake.jpg'), $old);
            self::fail('Expected ImageProcessingException');
        } catch (ImageProcessingException) {
            self::assertFileExists($this->images->absolutePath($old));
        }
        $this->images->remove($old);
    }

    /** A tiny PNG whose header claims a huge size (decompression bomb). */
    public static function hugePngHeader(string $dir): string
    {
        $img = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        // IHDR data starts at byte 16: width (4), height (4); its CRC follows the 13 data bytes
        $ihdr = pack('NN', 12000, 12000).substr($png, 24, 5);
        $png = substr($png, 0, 16).$ihdr.pack('N', crc32('IHDR'.$ihdr)).substr($png, 33);
        $path = $dir.'/bomb_'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($path, $png);

        return $path;
    }

    public function testHugeDimensionsAreRejectedBeforeDecoding(): void
    {
        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('too large');

        $this->images->storePhoto(new File(self::hugePngHeader($this->tmp)), null);
    }

    /** Landscape JPEG, left half red / right half blue, tagged EXIF Orientation 6 (rotate 90° CW to view). */
    private function rotatedPhoneJpeg(): File
    {
        $img = imagecreatetruecolor(400, 300);
        imagefilledrectangle($img, 0, 0, 199, 299, imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 200, 0, 399, 299, imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 95);
        $jpeg = ob_get_clean();

        $tiff = "II*\0".pack('V', 8).pack('v', 1).pack('vvVvv', 0x0112, 3, 1, 6, 0).pack('V', 0);
        $app1 = "Exif\0\0".$tiff;
        $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', \strlen($app1) + 2).$app1.substr($jpeg, 2);
        $path = $this->tmp.'/phone.jpg';
        file_put_contents($path, $jpeg);

        return new File($path);
    }

    public function testExifOrientationIsApplied(): void
    {
        $path = $this->images->storePhoto($this->rotatedPhoneJpeg(), null);
        $img = imagecreatefromwebp($this->images->absolutePath($path));

        $top = imagecolorsforindex($img, imagecolorat($img, 175, 30));
        $bottom = imagecolorsforindex($img, imagecolorat($img, 175, 420));
        self::assertGreaterThan(200, $top['red'], 'Top should be the red (left) half after rotating');
        self::assertGreaterThan(200, $bottom['blue'], 'Bottom should be the blue (right) half after rotating');
        $this->images->remove($path);
    }

    public function testRemoveIgnoresPathsOutsideUploads(): void
    {
        $keep = $this->images->absolutePath('keep.txt');
        @mkdir(\dirname($keep), 0o777, true);
        file_put_contents($keep, 'x');

        $this->images->remove('keep.txt');
        $this->images->remove('uploads/photos/../../keep.txt');

        self::assertFileExists($keep);
        unlink($keep);
    }
}
