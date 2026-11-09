<?php

declare(strict_types=1);

namespace App\Grading\Infrastructure\CardSlip;

use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\Core\Domain\Service\Pdf\PdfRendererInterface;
use App\Grading\Domain\Service\CardSlip\CardSlip;
use App\Grading\Domain\Service\CardSlip\CardSlipPdfInterface;
use Twig\Environment;

/**
 * Card slips as a print-ready PDF: US Letter ("short bond") portrait pages, two 8.5 × 5.5 in slips
 * per page with a dashed cut line between them. One slip makes one page with the slip in the top half.
 */
class CardSlipPdf implements CardSlipPdfInterface
{
    public function __construct(
        private readonly PdfRendererInterface $pdf,
        private readonly Environment $twig,
        private readonly ImageProcessorInterface $images,
    ) {
    }

    public function render(array $slips): string
    {
        return $this->pdf->render($this->html($slips));
    }

    public function html(array $slips): string
    {
        $logos = [];
        foreach ($slips as $slip) {
            if (null !== $slip->logoPath && !\array_key_exists($slip->logoPath, $logos)) {
                $logos[$slip->logoPath] = $this->logoDataUri($slip->logoPath);
            }
        }

        return $this->twig->render(
            '@Grading/card_slip/pdf.html.twig',
            ['pages' => array_chunk($slips, 2), 'logos' => $logos],
        );
    }

    /** The stored .webp logo as a PNG data URI (Dompdf may not load files, nor WebP), or null when it can't be read. */
    private function logoDataUri(string $path): ?string
    {
        $file = $this->images->absolutePath($path);
        $image = is_file($file) ? @imagecreatefromwebp($file) : false;
        if (!$image instanceof \GdImage) {
            return null;
        }
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }
}
