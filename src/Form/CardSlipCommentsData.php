<?php

namespace App\Form;

use App\CardSlip\CardSlip;

/** A class's card slip comments for a term: the ticked codes and the typed comment of each learner, by enrollment id. */
class CardSlipCommentsData
{
    /** @param array<int, array{codes: list<string>, text: ?string}> $entries by enrollment id */
    public function __construct(public array $entries = [])
    {
    }

    /**
     * The comments the slips print now: what was saved, else the suggestions.
     *
     * @param list<CardSlip> $slips
     */
    public static function of(array $slips): self
    {
        $entries = [];
        foreach ($slips as $slip) {
            $entries[$slip->enrollmentId] = ['codes' => $slip->chosenCodes, 'text' => $slip->customText];
        }

        return new self($entries);
    }
}
