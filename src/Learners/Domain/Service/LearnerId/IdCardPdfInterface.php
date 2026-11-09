<?php

declare(strict_types=1);

namespace App\Learners\Domain\Service\LearnerId;

/** Prints learners' ID cards as a PDF. */
interface IdCardPdfInterface
{
    /**
     * @param list<IdCard> $cards
     *
     * @return string the PDF's content
     */
    public function render(array $cards, IdLayout $layout): string;
}
