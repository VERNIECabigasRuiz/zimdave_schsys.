<?php

namespace App\LearnerId;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/** Learner ID cards (CR80, 54 × 86 mm) as a PDF, on A4 sheets or card-sized pages. */
class IdCardPdf
{
    public const PER_SHEET = 9;
    private const PER_ROW = 3;

    public function __construct(private readonly Environment $twig)
    {
    }

    /** @param list<IdCard> $cards */
    public function render(array $cards, IdLayout $layout): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($cards, $layout));
        // the page size is set by @page in the templates
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** @param list<IdCard> $cards */
    public function html(array $cards, IdLayout $layout): string
    {
        return IdLayout::Sheet === $layout
            ? $this->twig->render('learners/id/sheet.html.twig', ['pages' => self::sheetPages($cards), 'per_row' => self::PER_ROW])
            : $this->twig->render('learners/id/card.html.twig', ['cards' => $cards]);
    }

    /**
     * Sheets of 9: the fronts in order, then the backs with each row of three reversed, so that
     * printing double-sided (flip on long edge) puts every back behind its front.
     *
     * @param list<IdCard> $cards
     *
     * @return list<array{front: list<?IdCard>, back: list<?IdCard>}>
     */
    public static function sheetPages(array $cards): array
    {
        $pages = [];
        foreach (array_chunk($cards, self::PER_SHEET) as $chunk) {
            $front = array_pad($chunk, self::PER_SHEET, null);
            $back = array_merge(...array_map(array_reverse(...), array_chunk($front, self::PER_ROW)));
            $pages[] = ['front' => $front, 'back' => $back];
        }

        return $pages;
    }
}
