<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\FileStorage;

use App\Core\Domain\Exception\ImageProcessingException;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Every upload is decoded and re-encoded with GD, which drops EXIF/GPS data and
 * anything hidden in the original file.
 */
class ImageProcessor implements ImageProcessorInterface
{
    private const STORED_PATH = '#^uploads/(photos|logos|signatures)/[0-9a-f]{32}\.webp$#';

    public function __construct(
        #[Autowire('%app.upload_root%')]
        private readonly string $uploadRoot,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function storePhoto(string $sourcePath, ?string $oldPath): string
    {
        $source = $this->decode($sourcePath);
        $target = $this->cropToRatio($source, self::PHOTO_WIDTH, self::PHOTO_HEIGHT);

        return $this->save($target, 'photos', 80, $oldPath);
    }

    public function storeLogo(string $sourcePath, ?string $oldPath): string
    {
        $source = $this->decode($sourcePath);
        $target = $this->fitWithin($source, self::LOGO_MAX);

        return $this->save($target, 'logos', 85, $oldPath);
    }

    public function storeSignature(string $sourcePath, ?string $oldPath): string
    {
        $source = $this->decode($sourcePath);
        $target = $this->fitWithin($source, self::SIGNATURE_MAX);

        return $this->save($target, 'signatures', 90, $oldPath);
    }

    public function remove(?string $path): void
    {
        if (null !== $path && preg_match(self::STORED_PATH, $path)) {
            $this->filesystem->remove($this->absolutePath($path));
        }
    }

    public function absolutePath(string $path): string
    {
        return $this->uploadRoot . '/' . $path;
    }

    private function decode(string $path): \GdImage
    {
        $size = @getimagesize($path);
        if (false !== $size && $size[0] * $size[1] > self::MAX_PIXELS) {
            // Checked before decoding: a few KB can claim a size that needs gigabytes of memory
            throw new ImageProcessingException('The image is too large. Use one under 25 megapixels.');
        }

        $data = @file_get_contents($path);
        $image = false !== $data && '' !== $data ? @imagecreatefromstring($data) : false;
        if (!$image instanceof \GdImage) {
            throw new ImageProcessingException('The file is not a readable image.');
        }

        $isJpeg = false !== $size && \IMAGETYPE_JPEG === $size[2];

        return $isJpeg && \function_exists('exif_read_data') ? $this->applyExifOrientation($image, $path) : $image;
    }

    /** Phones save portrait photos sideways plus an EXIF "rotate me" tag, which GD ignores. */
    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        $exif = @exif_read_data($path);
        $tag = \is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;
        $orientation = is_numeric($tag) ? (int) $tag : 1;

        // imagerotate() turns counter-clockwise
        $turned = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, 270, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };
        // If turning fails the photo stays as it was
        $rotated = $turned ?: $image;
        if (\in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, \IMG_FLIP_HORIZONTAL);
        }

        return $rotated;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function cropToRatio(\GdImage $source, int $width, int $height): \GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $cropW = min($sw, (int) round($sh * $width / $height));
        $cropH = min($sh, (int) round($sw * $height / $width));

        $target = imagecreatetruecolor($width, $height);
        $left = intdiv($sw - $cropW, 2);
        $top = intdiv($sh - $cropH, 2);
        imagecopyresampled($target, $source, 0, 0, $left, $top, $width, $height, $cropW, $cropH);

        return $target;
    }

    private function fitWithin(\GdImage $source, int $max): \GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $scale = min(1, $max / max($sw, $sh));
        $width = max(1, (int) round($sw * $scale));
        $height = max(1, (int) round($sh * $scale));

        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sw, $sh);

        return $target;
    }

    private function save(\GdImage $image, string $folder, int $quality, ?string $oldPath): string
    {
        $path = \sprintf('uploads/%s/%s.webp', $folder, bin2hex(random_bytes(16)));
        $absolute = $this->absolutePath($path);
        $this->filesystem->mkdir(\dirname($absolute));
        imagewebp($image, $absolute, $quality) || throw new ImageProcessingException('The image could not be saved.');
        $this->remove($oldPath);

        return $path;
    }
}
