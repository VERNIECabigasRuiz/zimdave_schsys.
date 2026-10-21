<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Stores profile photos and school logos as small WebP files.
 *
 * Every upload is decoded and re-encoded with GD, which drops EXIF/GPS data and
 * anything hidden in the original file. Stored paths are relative to the public
 * directory so templates can pass them to asset().
 */
class ImageProcessor
{
    /** Passport photo ratio on the PDS: 3.5 cm × 4.5 cm. */
    public const PHOTO_WIDTH = 350;
    public const PHOTO_HEIGHT = 450;
    public const LOGO_MAX = 400;
    public const MAX_PIXELS = 25_000_000;

    private const STORED_PATH = '#^uploads/(photos|logos)/[0-9a-f]{32}\.webp$#';

    public function __construct(
        #[Autowire('%app.upload_root%')]
        private readonly string $uploadRoot,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function storePhoto(File $file, ?string $oldPath): string
    {
        $source = $this->decode($file);
        $target = $this->cropToRatio($source, self::PHOTO_WIDTH, self::PHOTO_HEIGHT);

        return $this->save($target, 'photos', 80, $oldPath);
    }

    public function storeLogo(File $file, ?string $oldPath): string
    {
        $source = $this->decode($file);
        $target = $this->fitWithin($source, self::LOGO_MAX);

        return $this->save($target, 'logos', 85, $oldPath);
    }

    /** Deletes a stored image; ignores null and anything that isn't one of ours. */
    public function remove(?string $path): void
    {
        if (null !== $path && preg_match(self::STORED_PATH, $path)) {
            $this->filesystem->remove($this->absolutePath($path));
        }
    }

    public function absolutePath(string $path): string
    {
        return $this->uploadRoot.'/'.$path;
    }

    private function decode(File $file): \GdImage
    {
        $size = @getimagesize($file->getPathname());
        if (false !== $size && $size[0] * $size[1] > self::MAX_PIXELS) {
            // Checked before decoding: a few KB can claim a size that needs gigabytes of memory
            throw new ImageProcessingException('The image is too large. Use one under 25 megapixels.');
        }

        $data = @file_get_contents($file->getPathname());
        $image = false !== $data && '' !== $data ? @imagecreatefromstring($data) : false;
        if (!$image instanceof \GdImage) {
            throw new ImageProcessingException('The file is not a readable image.');
        }

        return $this->applyExifOrientation($image, $file, $size);
    }

    /** Phones save portrait photos sideways plus an EXIF "rotate me" tag, which GD ignores. */
    private function applyExifOrientation(\GdImage $image, File $file, array|false $size): \GdImage
    {
        if (false === $size || \IMAGETYPE_JPEG !== $size[2] || !\function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (int) (@exif_read_data($file->getPathname())['Orientation'] ?? 1);

        // imagerotate() turns counter-clockwise
        $image = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, 270, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if (\in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, \IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    private function cropToRatio(\GdImage $source, int $width, int $height): \GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $cropW = min($sw, (int) round($sh * $width / $height));
        $cropH = min($sh, (int) round($sw * $height / $width));

        $target = imagecreatetruecolor($width, $height);
        imagecopyresampled($target, $source, 0, 0, intdiv($sw - $cropW, 2), intdiv($sh - $cropH, 2), $width, $height, $cropW, $cropH);

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
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sw, $sh);

        return $target;
    }

    private function save(\GdImage $image, string $folder, int $quality, ?string $oldPath): string
    {
        $path = \sprintf('uploads/%s/%s.webp', $folder, bin2hex(random_bytes(16)));
        $absolute = $this->absolutePath($path);
        $this->filesystem->mkdir(\dirname($absolute));
        if (!imagewebp($image, $absolute, $quality)) {
            throw new ImageProcessingException('The image could not be saved.');
        }
        $this->remove($oldPath);

        return $path;
    }
}
