<?php

namespace App\Service;

use App\Core\Domain\Service\Pdf\PdfRendererInterface;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\Entity\PersonalDataSheet;
use Twig\Environment;

/**
 * Prints a Personal Data Sheet on the layout of CS Form No. 212 (Revised 2026):
 * four A4 pages, plus continuation pages when a list has more rows than the form.
 */
class PdsPdfRenderer
{
    /** Rows the official form has for each list. */
    public const CHILD_ROWS = 12;

    public function __construct(
        private readonly PdfRendererInterface $pdf,
        private readonly Environment $twig,
        private readonly ImageProcessorInterface $images,
    ) {
    }

    public function render(PersonalDataSheet $sheet): string
    {
        return $this->pdf->render($this->renderHtml($sheet), 'A4', 'portrait');
    }

    public function renderHtml(PersonalDataSheet $sheet): string
    {
        $children = array_values($sheet->getChildren()->toArray());

        return $this->twig->render('profile/pdf/pds.html.twig', [
            'sheet' => $sheet,
            'photo' => $this->photoDataUri($sheet),
            'children' => \array_slice($children, 0, self::CHILD_ROWS),
            'more_children' => \array_slice($children, self::CHILD_ROWS),
            'child_rows' => self::CHILD_ROWS,
            'lists' => PdsListGrids::build($sheet),
            'list_rows' => PdsListGrids::ROWS,
        ]);
    }

    /** "PDS_DELA-CRUZ_JUAN.pdf"; "PDS.pdf" while the name is empty. */
    public function filename(PersonalDataSheet $sheet): string
    {
        $parts = array_filter(array_map(
            static fn (?string $part) => trim((string) preg_replace('/[^A-Z0-9]+/', '-', mb_strtoupper((string) $part)), '-'),
            [$sheet->surname, $sheet->firstName],
        ));

        return implode('_', ['PDS', ...$parts]).'.pdf';
    }

    /** The photo as an inline PNG (Dompdf is not allowed to load files or URLs). */
    private function photoDataUri(PersonalDataSheet $sheet): ?string
    {
        $path = $sheet->getUser()->getPhotoPath();
        $file = $path ? $this->images->absolutePath($path) : null;
        $image = $file && is_file($file) ? @imagecreatefromwebp($file) : false;
        if (!$image instanceof \GdImage) {
            return null;
        }

        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
