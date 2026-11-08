<?php

declare(strict_types=1);

namespace App\Learners\Infrastructure\LearnerId;

use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\Learners\Domain\Service\LearnerId\IdCardImagesInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The images ID cards print, inlined as data URIs. */
final class IdCardImages implements IdCardImagesInterface
{
    private const SEAL = '/assets/images/deped-seal.png';

    public function __construct(
        private readonly ImageProcessorInterface $images,
        private readonly QrCode $qrCode,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function logo(string $path): ?string
    {
        return $this->webpDataUri($this->images->absolutePath($path));
    }

    public function photo(string $path): ?string
    {
        return $this->webpDataUri($this->images->absolutePath($path), jpeg: true);
    }

    public function seal(): ?string
    {
        $file = $this->projectDir . self::SEAL;

        return is_file($file) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($file)) : null;
    }

    public function qr(string $text): string
    {
        return $this->qrCode->png($text);
    }

    /**
     * A stored .webp inlined (Dompdf may not load files): photos as JPEG, which Dompdf embeds as is,
     * logos as PNG to keep their transparency. An RGBA PNG goes through Dompdf's per-pixel PHP loop,
     * far too slow for a section's worth of photos.
     */
    private function webpDataUri(string $file, bool $jpeg = false): ?string
    {
        $image = is_file($file) ? @imagecreatefromwebp($file) : false;
        if (!$image instanceof \GdImage) {
            return null;
        }

        ob_start();
        $jpeg ? imagejpeg($image, null, 90) : imagepng($image);

        return 'data:image/' . ($jpeg ? 'jpeg' : 'png') . ';base64,' . base64_encode((string) ob_get_clean());
    }
}
