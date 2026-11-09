<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

/**
 * An admin adds a school year. Bound to the school year form, prefilled with GetSchoolYearFormCommand's suggestion.
 *
 * @phpstan-import-type SchoolYearData from \App\Schools\Application\Transformer\SchoolYearTransformer
 */
final class CreateSchoolYearCommand extends SchoolYearDetails
{
    /** @param SchoolYearData $suggestion */
    public static function fromSuggestion(array $suggestion): self
    {
        $command = new self();
        $command->name = $suggestion['name'];
        $command->startDate = $suggestion['startDate'];
        $command->endDate = $suggestion['endDate'];

        return $command;
    }
}
