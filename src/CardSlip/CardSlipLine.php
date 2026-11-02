<?php

namespace App\CardSlip;

/** A subject on a card slip; a parent subject (MAPEH) carries its components' grades in $components. */
final readonly class CardSlipLine
{
    public function __construct(
        public string $label,
        public ?int $grade,
        public string $remarks,
        /** "Music 85 · Arts 88 · PE 90 · Health 86", or null for a subject without components */
        public ?string $components,
    ) {
    }
}
