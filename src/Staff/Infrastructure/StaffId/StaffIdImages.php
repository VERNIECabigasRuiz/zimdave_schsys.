<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure\StaffId;

use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\Staff\Domain\Service\StaffId\StaffIdImagesInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The images staff IDs print, inlined as data URIs. */
final class StaffIdImages implements StaffIdImagesInterface
{
    private const SEAL = '/resources/images/deped-seal.png';

    public function __construct(
        private readonly ImageProcessorInterface $images,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function photo(?string $path): ?string
    {
        return $this->webpDataUri($path, jpeg: true);
    }

    public function logo(?string $path): ?string
    {
        return $this->webpDataUri($path);
    }

    public function signature(?string $path): ?string
    {
        return $this->webpDataUri($path);
    }

    public function seal(): ?string
    {
        $file = $this->projectDir . self::SEAL;

        return is_file($file) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($file)) : null;
    }

    /**
     * A stored .webp inlined (Dompdf may not load files): photos as JPEG, which Dompdf embeds as is,
     * signatures and logos as PNG to keep their transparency.
     */
    private function webpDataUri(?string $path, bool $jpeg = false): ?string
    {
        $file = null === $path ? null : $this->images->absolutePath($path);
        $image = null !== $file && is_file($file) ? @imagecreatefromwebp($file) : false;
        if (!$image instanceof \GdImage) {
            return null;
        }

        ob_start();
        if ($jpeg) {
            imagejpeg($image, null, 90);
        } else {
            imagesavealpha($image, true);
            imagepng($image);
        }

        return 'data:image/' . ($jpeg ? 'jpeg' : 'png') . ';base64,' . base64_encode((string) ob_get_clean());
    }
}
