<?php

declare(strict_types=1);

namespace App\Staff\Domain\Service\StaffId;

/** The file names of staff ID downloads. */
final class StaffIdFileName
{
    /** "ID (Big) - DELA CRUZ, JUAN.pdf": the surname and the first given name; "/", "\" and "%" become "-" */
    public static function forCard(StaffIdCard $card, StaffIdSize $size): string
    {
        $first = explode(' ', $card->givenNames)[0];
        $name = $card->surname === $card->givenNames ? $card->surname : $card->surname . ', ' . $first;

        return \sprintf('ID (%s) - %s.pdf', $size->label(), preg_replace('/[\/\\\\%]/', '-', $name));
    }
}
