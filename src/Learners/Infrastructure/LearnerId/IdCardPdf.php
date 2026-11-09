<?php

declare(strict_types=1);

namespace App\Learners\Infrastructure\LearnerId;

use App\Core\Domain\Service\Pdf\PdfRendererInterface;
use App\Learners\Domain\Service\LearnerId\IdCard;
use App\Learners\Domain\Service\LearnerId\IdCardPdfInterface;
use App\Learners\Domain\Service\LearnerId\IdLayout;
use Twig\Environment;

/** Learner ID cards (CR80, 54 × 86 mm) as a PDF, on A4 sheets or card-sized pages. */
class IdCardPdf implements IdCardPdfInterface
{
    public const PER_SHEET = 9;
    private const PER_ROW = 3;

    public function __construct(
        private readonly PdfRendererInterface $pdf,
        private readonly Environment $twig,
    ) {
    }

    public function render(array $cards, IdLayout $layout): string
    {
        return $this->pdf->render($this->html($cards, $layout));
    }

    /** @param list<IdCard> $cards */
    public function html(array $cards, IdLayout $layout): string
    {
        return IdLayout::Sheet === $layout
            ? $this->twig->render(
                '@Learners/learners/id/sheet.html.twig',
                ['pages' => self::sheetPages($cards), 'per_row' => self::PER_ROW],
            )
            : $this->twig->render('@Learners/learners/id/card.html.twig', ['cards' => $cards]);
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
