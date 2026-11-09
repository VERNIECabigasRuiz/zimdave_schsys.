<?php

declare(strict_types=1);

namespace App\Learners\Domain\Service\LearnerId;

/** How ID cards are laid out on paper */
enum IdLayout: string
{
    /** A4 sheets of 9 cards, fronts then mirrored backs, for printing double-sided and cutting */
    case Sheet = 'sheet';

    /** One card-sized page per side, for PVC ID card printers */
    case Card = 'card';
}
