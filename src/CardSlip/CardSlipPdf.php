<?php

namespace App\CardSlip;

use App\Entity\GradingPeriod;
use App\Entity\Section;
use App\Service\ImageProcessor;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Card slips as a print-ready PDF: US Letter ("short bond") portrait pages, two 8.5 × 5.5 in slips
 * per page with a dashed cut line between them. One slip makes one page with the slip in the top half.
 */
class CardSlipPdf
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ImageProcessor $images,
    ) {
    }

    /** @param list<CardSlip> $slips */
    public function render(array $slips): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($slips));
        // the page size is set by @page in the template
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** @param list<CardSlip> $slips */
    public function html(array $slips): string
    {
        $logos = [];
        foreach ($slips as $slip) {
            if (null !== $slip->logoPath && !\array_key_exists($slip->logoPath, $logos)) {
                $logos[$slip->logoPath] = $this->logoDataUri($slip->logoPath);
            }
        }

        return $this->twig->render('card_slip/pdf.html.twig', ['pages' => array_chunk($slips, 2), 'logos' => $logos]);
    }

    /** "Card slips - Grade 7 ACTIVE - Term 1.pdf" */
    public static function filename(Section $section, GradingPeriod $period): string
    {
        $gradeSection = trim(($section->getGradeLevel()?->label() ?? '').' '.mb_strtoupper($section->getName()));

        return \sprintf('Card slips - %s - %s.pdf', self::safe($gradeSection), $period->getLabel());
    }

    /** "Card slip - DELA CRUZ, JUAN - Term 1.pdf" */
    public static function learnerFilename(CardSlip $slip): string
    {
        return \sprintf('Card slip - %s - %s.pdf', self::safe($slip->learnerName), $slip->periodLabel);
    }

    /** "/", "\" and "%" become "-", a download filename can't have them (as the SF1 and ID filenames do) */
    private static function safe(string $text): string
    {
        return (string) preg_replace('/[\/\\\\%]/', '-', $text);
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

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
